<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Documents\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;

/**
 * Documents de la Maternelle privée émis par le module Documents du socle (DOC-MAT-01 à 04) : numéro continu, contenu FIGÉ à
 * l'émission, QR de vérification publique (sauf registre), révocation. Un carnet, une fiche de liaison ou une carte n'est émis qu'une
 * fois : une nouvelle demande rend le document déjà valide (réédition par Duplicata ou Rectificatif du socle). Le registre d'un jour
 * peut être réédité : l'ancien est révoqué (« remplacé ») et le nouveau porte un nouveau numéro. Aucun document hors catalogue.
 */
final class NurseryDocuments
{
    public const TABLE = 'nu_issued_documents';
    public const PROGRESS_BOOK = 'progress_book';
    public const PICKUP_CARD = 'pickup_card';
    public const EXIT_REGISTER = 'exit_register';
    public const LIAISON_SHEET = 'liaison_sheet';

    /** Nature => type du catalogue Documents (migration nursery_document_types). */
    public const TYPES = [
        self::PROGRESS_BOOK => 'nu_progress_book',
        self::PICKUP_CARD => 'nu_pickup_card',
        self::EXIT_REGISTER => 'nu_exit_register',
        self::LIAISON_SHEET => 'nu_liaison_sheet',
    ];

    /** Natures visibles du parent sur le portail (jamais le registre). */
    public const PARENT_KINDS = [self::PROGRESS_BOOK, self::PICKUP_CARD, self::LIAISON_SHEET];

    public const LOCALES = ['fr', 'en'];

    public function __construct(
        private readonly DocumentIssuer $issuer,
        private readonly Store $store,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * Émet le document de cette nature pour cet objet, ou rend celui qui est déjà valide (sauf registre : remplacé).
     *
     * @param array<string, scalar|null> $data variables déclarées par le type
     * @return array<string, mixed>
     */
    public function issue(Principal $principal, string $establishmentId, string $kind, string $sourceId, ?string $learnerId, string $subjectPersonId, array $data, string $locale): array
    {
        $type = self::TYPES[$kind] ?? throw new \InvalidArgumentException('Nature de document inconnue : ' . $kind);
        if (!in_array($locale, self::LOCALES, true)) {
            throw HttpException::validation(['locale' => ['Langue : fr ou en.']]);
        }
        $tenantId = $principal->tenantId;
        $existing = $this->validFor($tenantId, $establishmentId, $kind, $sourceId);
        if ($existing !== null && $kind !== self::EXIT_REGISTER) {
            return self::resource($existing) + ['already_issued' => true];
        }
        $document = $this->issuer->issue($tenantId, $establishmentId, $type, $subjectPersonId, $data, $principal->userId, $locale);
        $id = (string) new Ulid();
        try {
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
                'id' => $id, 'kind' => $kind, 'source_id' => $sourceId, 'learner_id' => $learnerId, 'document_id' => $document->id, 'doc_number' => $document->number,
                'verification_token' => $document->verificationToken, 'locale' => $locale, 'status' => IssuedDocument::VALID, 'issued_by' => $principal->userId,
                'issued_at' => $this->clock->now(), 'revoked_at' => null, 'revoke_reason' => null,
            ]);
        } catch (\Throwable $e) {
            // Jamais de document valide sans trace dans l'application : le document du socle est aussitôt révoqué.
            $this->issuer->revoke($tenantId, $document->id, 'Émission non enregistrée par la Maternelle privée.', $principal->userId);

            throw $e;
        }
        if ($existing !== null) {
            $this->revokeRow($principal->tenantId, $establishmentId, $existing, 'Remplacé par le document ' . $document->number . '.', $principal->userId);
        }
        $this->access->audit($principal, 'nursery.document.issued', 'document', $document->id, null, ['kind' => $kind, 'source_id' => $sourceId, 'number' => $document->number]);

        return self::resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id)) + ['already_issued' => false];
    }

    /** Révoque le document valide de cet objet (autorisation retirée, carnet rectifié…) ; sans effet s'il n'y en a pas. */
    public function revokeFor(string $tenantId, string $establishmentId, string $kind, string $sourceId, string $reason, string $actorId): bool
    {
        $row = $this->validFor($tenantId, $establishmentId, $kind, $sourceId);
        if ($row === null) {
            return false;
        }
        $this->revokeRow($tenantId, $establishmentId, $row, $reason, $actorId);

        return true;
    }

    /** @return array<string, mixed>|null */
    public function validFor(string $tenantId, string $establishmentId, string $kind, string $sourceId): ?array
    {
        return $this->store->select(self::TABLE, $tenantId, $establishmentId, ['kind' => $kind, 'source_id' => $sourceId, 'status' => IssuedDocument::VALID], 'issued_at DESC, id', 1)[0] ?? null;
    }

    /**
     * Registre des documents émis (personnel) : filtres par nature, enfant et état.
     *
     * @param list<string>|null $learnerIds enfants visibles (null = tous ceux de l'établissement)
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function page(string $tenantId, string $establishmentId, Paging $paging, ?string $kind, ?string $learnerId, ?array $learnerIds, ?string $status = null): array
    {
        $where = array_filter(['kind' => $kind, 'learner_id' => $learnerId, 'status' => $status], static fn (?string $v): bool => $v !== null);
        $rows = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, $where, 'issued_at DESC, id', 2000) as $row) {
            if ($learnerIds === null || ($row['learner_id'] !== null && in_array((string) $row['learner_id'], $learnerIds, true))) {
                $rows[] = self::resource($row);
            }
        }

        return $paging->slice($rows);
    }

    /**
     * Documents VALIDES d'un enfant pour son parent (carnet, carte, fiche ; jamais le registre).
     *
     * @return list<array<string, mixed>>
     */
    public function forParent(string $tenantId, string $establishmentId, string $learnerId): array
    {
        $rows = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'status' => IssuedDocument::VALID], 'issued_at DESC, id', 200) as $row) {
            if (in_array((string) $row['kind'], self::PARENT_KINDS, true)) {
                $rows[] = self::resource($row);
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function revokeRow(string $tenantId, string $establishmentId, array $row, string $reason, string $actorId): void
    {
        $this->issuer->revoke($tenantId, (string) $row['document_id'], $reason, $actorId);
        $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => IssuedDocument::REVOKED, 'revoked_at' => $this->clock->now(), 'revoke_reason' => mb_substr($reason, 0, 255)], ['id' => (string) $row['id']]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'kind' => (string) $row['kind'], 'source_id' => (string) $row['source_id'],
            'learner_id' => $row['learner_id'] !== null ? (string) $row['learner_id'] : null, 'document_id' => (string) $row['document_id'],
            'number' => (string) $row['doc_number'], 'verification_token' => (string) $row['verification_token'], 'verification_path' => '/api/v1/public/verify/' . (string) $row['verification_token'],
            'locale' => (string) $row['locale'], 'status' => (string) $row['status'], 'issued_at' => (string) $row['issued_at'],
            'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null, 'revoke_reason' => $row['revoke_reason'] !== null ? (string) $row['revoke_reason'] : null,
        ];
    }
}
