<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Documents\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Gate\Application\GateService;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\CustodyRestrictionService;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScope;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/**
 * Documents de la porte de la maternelle publique, émis par le module Documents du socle (T727) : DOC-21 carte d'une personne
 * autorisée (numéro et QR de vérification sans nom ni téléphone, révoquée avec l'autorisation, jamais pour une personne visée par une
 * restriction de garde) et DOC-19 registre du jour (sans QR, ni signataire ; une nouvelle édition révoque la précédente). Le contenu
 * est figé à l'émission ; chaque émission est inscrite dans `pn_issued_documents` pour retrouver et révoquer le bon document.
 */
final class PickupDocuments
{
    public const TABLE = 'pn_issued_documents';
    public const CARD = 'pickup_card';
    public const REGISTER = 'register';

    /** Nature => type du catalogue Documents (migration `public_nursery_document_types`). */
    public const TYPES = [self::CARD => 'pn_doc21_pickup_card', self::REGISTER => 'pn_doc19_register'];
    public const LOCALES = ['fr', 'en'];

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly DocumentIssuer $issuer,
        private readonly AuthorizedPickupRegistry $pickups,
        private readonly CustodyRestrictionService $restrictions,
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitTree $tree,
        private readonly GateService $gate,
        private readonly UserPersonLink $links,
        private readonly PublicNurseryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * DOC-21 : carte d'une autorisation ACTIVE valable aujourd'hui. Déjà émise et valide : rend la même carte.
     *
     * @return array<string, mixed>
     */
    public function pickupCard(Principal $principal, string $establishmentId, SectionScope $scope, string $pickupId, string $locale): array
    {
        $this->assertLocale($locale);
        $tenantId = $principal->tenantId;
        $pickup = $this->pickups->find($tenantId, $establishmentId, $pickupId) ?? throw HttpException::notFound('Autorisation introuvable.');
        $enrollment = $this->enrollment($tenantId, $establishmentId, $pickup->learnerId);
        if ($enrollment->structureId !== null && !$scope->contains($enrollment->structureId)) {
            $this->audit->append($tenantId, $principal->userId, 'public_nursery.scope.denied', 'authorization', $pickupId, null, ['section_id' => $enrollment->structureId]);

            throw HttpException::notFound('Autorisation introuvable.');
        }
        $today = $this->clock->today();
        if ($pickup->status !== AuthorizedPickupInfo::ACTIVE || !$pickup->isValidOn($pickup->grantType === AuthorizedPickupInfo::DAY ? (string) $pickup->validOn : $today)) {
            throw HttpException::conflict('Autorisation retirée ou expirée : aucune carte.');
        }
        foreach ($this->restrictions->activeFor($tenantId, $establishmentId, $pickup->learnerId, $today) as $restriction) {
            if ((string) $restriction['restricted_pickup_id'] === $pickup->id) {
                // La personne visée par une restriction ne reçoit jamais de carte ; refus journalisé sans détail exposé.
                $this->audit->append($tenantId, $principal->userId, 'public_nursery.pickup.card_refused', 'authorization', $pickupId, null, ['reason' => 'custody_restriction']);

                throw HttpException::conflict('Aucune carte ne peut être émise pour cette personne. Voyez la direction.');
            }
        }
        $existing = $this->validFor($tenantId, $establishmentId, self::CARD, $pickupId);
        if ($existing !== null) {
            return self::resource($existing) + ['already_issued' => true];
        }
        $section = $enrollment->structureId !== null ? $this->tree->structure($tenantId, $establishmentId, $enrollment->structureId)?->name : null;
        $grant = $pickup->grantType === AuthorizedPickupInfo::DAY
            ? ($locale === 'en' ? 'on ' : 'le ') . (string) $pickup->validOn
            : ($locale === 'en' ? 'permanent, until withdrawn' : 'permanente, jusqu’au retrait');

        return $this->issue($principal, $establishmentId, self::CARD, $pickupId, $pickup->learnerId, $pickup->learnerId, [
            'section_label' => $section ?? '', 'person_name' => $pickup->name, 'relationship' => $pickup->relationship, 'grant_label' => $grant,
        ], $locale) + ['already_issued' => false];
    }

    /**
     * DOC-19 : registre du jour de toute l'école, au nom de la personne qui l'édite. Une réédition révoque l'édition précédente.
     *
     * @return array<string, mixed>
     */
    public function register(Principal $principal, string $establishmentId, SectionScope $scope, string $day, string $locale): array
    {
        $this->assertLocale($locale);
        if (!$scope->isWholeEstablishment()) {
            $this->audit->append($principal->tenantId, $principal->userId, 'public_nursery.scope.denied', 'register', $day, null, ['scope' => 'sections']);

            throw HttpException::forbidden('Le registre du jour couvre toute l’école : réservé à la direction.');
        }
        $personId = $this->links->personIdForUser($principal->tenantId, $principal->userId)
            ?? throw HttpException::forbidden('Le registre est édité au nom d’une personne : rattachez ce compte à une fiche personne.');
        $register = $this->gate->register($principal->tenantId, $establishmentId, $scope, $day);
        $en = $locale === 'en';
        $labels = $en
            ? ['left' => 'left', 'still_at_school' => 'still at school', 'kept' => 'kept at school', 'not_arrived' => 'not arrived']
            : ['left' => 'sorti', 'still_at_school' => 'encore à l’école', 'kept' => 'maintenu à l’école', 'not_arrived' => 'non arrivé'];
        $rows = [];
        foreach ($register['children'] as $child) {
            $line = (string) $child['name'] . ' : ' . ($labels[(string) $child['status']] ?? (string) $child['status']);
            if ($child['arrived_at'] !== null) {
                $line .= ($en ? ', arrived ' : ', arrivé ') . substr((string) $child['arrived_at'], 11, 5);
            }
            if ($child['left_at'] !== null) {
                $line .= ($en ? ', left ' : ', sorti ') . substr((string) $child['left_at'], 11, 5) . ($en ? ' with ' : ' avec ') . (string) $child['left_to'];
            }
            if ((int) $child['refusals'] > 0) {
                $line .= ($en ? ', refusals: ' : ', refus : ') . (int) $child['refusals'];
            }
            if ($child['alerts'] !== []) {
                $line .= ($en ? ', alerts: ' : ', alertes : ') . implode(', ', (array) $child['alerts']);
            }
            $rows[] = $line;
        }
        $t = $register['totals'];
        $totals = $en
            ? sprintf('Children: %d. Arrived: %d. Left: %d. Still at school: %d. Kept at school: %d. Refusals: %d. Alerts: %d.', $t['children'], $t['arrived'], $t['left'], $t['still_at_school'], $t['kept'], $t['refusals'], $t['alerts'])
            : sprintf('Enfants : %d. Arrivés : %d. Sortis : %d. Encore à l’école : %d. Maintenus : %d. Refus : %d. Alertes : %d.', $t['children'], $t['arrived'], $t['left'], $t['still_at_school'], $t['kept'], $t['refusals'], $t['alerts']);
        $previous = $this->validFor($principal->tenantId, $establishmentId, self::REGISTER, $day);
        $issued = $this->issue($principal, $establishmentId, self::REGISTER, $day, null, $personId, [
            'register_date' => $day, 'totals_text' => $totals, 'rows_text' => implode("\n", $rows),
        ], $locale);
        if ($previous !== null) {
            $this->revokeRow($principal->tenantId, $establishmentId, $previous, 'Remplacé par le document ' . $issued['number'] . '.', $principal->userId);
        }

        return $issued;
    }

    /**
     * Documents émis de l'école (carte ou registre), du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $tenantId, string $establishmentId, ?string $kind, ?string $learnerId): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }
        if ($learnerId !== null) {
            $sql .= ' AND learner_id = ?';
            $params[] = $learnerId;
        }
        $query = $this->connections->pdo()->prepare($sql . ' ORDER BY issued_at DESC, id LIMIT 500');
        $query->execute($params);

        return array_values(array_map(self::resource(...), $query->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /**
     * @param array<string, scalar|null> $data
     * @return array<string, mixed>
     */
    private function issue(Principal $principal, string $establishmentId, string $kind, string $sourceId, ?string $learnerId, string $subjectPersonId, array $data, string $locale): array
    {
        $tenantId = $principal->tenantId;
        $document = $this->issuer->issue($tenantId, $establishmentId, self::TYPES[$kind], $subjectPersonId, $data, $principal->userId, $locale);
        $id = (string) new Ulid();
        try {
            $this->connections->pdo()->prepare(
                'INSERT INTO ' . self::TABLE . ' (id, tenant_id, establishment_id, kind, source_id, learner_id, document_id, doc_number, verification_token, locale, status, issued_by, issued_at, revoked_at, revoke_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)',
            )->execute([$id, $tenantId, $establishmentId, $kind, $sourceId, $learnerId, $document->id, $document->number, $document->verificationToken, $locale, IssuedDocument::VALID, $principal->userId, $this->clock->now()]);
        } catch (\Throwable $failure) {
            // Jamais de document valide sans trace dans l'application : le document du socle est aussitôt révoqué.
            $this->issuer->revoke($tenantId, $document->id, 'Émission non enregistrée par la Maternelle publique.', $principal->userId);

            throw $failure;
        }
        $this->audit->append($tenantId, $principal->userId, 'public_nursery.document.issued', 'document', $document->id, null, ['kind' => $kind, 'source_id' => $sourceId, 'number' => $document->number]);

        return self::resource($this->find($tenantId, $establishmentId, $id) ?? []);
    }

    /** @return array<string, mixed>|null */
    private function validFor(string $tenantId, string $establishmentId, string $kind, string $sourceId): ?array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE tenant_id = ? AND establishment_id = ? AND kind = ? AND source_id = ? AND status = ? ORDER BY issued_at DESC, id LIMIT 1',
        );
        $query->execute([$tenantId, $establishmentId, $kind, $sourceId, IssuedDocument::VALID]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $query->execute([$tenantId, $establishmentId, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function revokeRow(string $tenantId, string $establishmentId, array $row, string $reason, string $actorId): void
    {
        $this->issuer->revoke($tenantId, (string) $row['document_id'], $reason, $actorId);
        $this->connections->pdo()->prepare('UPDATE ' . self::TABLE . ' SET status = ?, revoked_at = ?, revoke_reason = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?')
            ->execute([IssuedDocument::REVOKED, $this->clock->now(), mb_substr($reason, 0, 255), $tenantId, $establishmentId, (string) $row['id']]);
    }

    private function enrollment(string $tenantId, string $establishmentId, string $learnerId): EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $learnerId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                return $enrollment;
            }
        }

        throw HttpException::notFound('Autorisation introuvable.');
    }

    private function assertLocale(string $locale): void
    {
        if (!in_array($locale, self::LOCALES, true)) {
            throw HttpException::validation(['locale' => ['Langue : fr ou en.']]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return $row === [] ? [] : [
            'id' => (string) $row['id'], 'kind' => (string) $row['kind'], 'source_id' => (string) $row['source_id'],
            'learner_id' => $row['learner_id'] !== null ? (string) $row['learner_id'] : null, 'document_id' => (string) $row['document_id'],
            'number' => (string) $row['doc_number'], 'verification_path' => '/api/v1/public/verify/' . (string) $row['verification_token'],
            'locale' => (string) $row['locale'], 'status' => (string) $row['status'], 'issued_at' => (string) $row['issued_at'],
            'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null, 'revoke_reason' => $row['revoke_reason'] !== null ? (string) $row['revoke_reason'] : null,
        ];
    }
}
