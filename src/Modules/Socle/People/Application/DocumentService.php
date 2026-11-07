<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use InvalidArgumentException;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\FileScanner;
use Scholaris\Modules\Socle\People\Domain\DocumentKind;
use Scholaris\Modules\Socle\People\Domain\UploadPolicy;
use Scholaris\Modules\Socle\People\Domain\UrlSigner;
use Scholaris\Modules\Socle\People\Infrastructure\FileStorage;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/** Dossier documentaire : téléversement contrôlé, URL signée temporaire, complétude et expiration. */
final class DocumentService
{
    public const EXPIRING_DAYS = 60;

    public function __construct(
        private readonly PdoStore $store,
        private readonly FileStorage $files,
        private readonly FileScanner $scanner,
        private readonly UrlSigner $signer,
        private readonly PeopleClock $clock,
        private readonly Config $config,
    ) {
    }

    /** @return array<string, mixed> */
    public function upload(string $tenantId, string $personId, string $kind, ?string $expiresOn, string $content, ?string $actorId): array
    {
        try {
            $type = UploadPolicy::inspect($content);
        } catch (InvalidArgumentException $e) {
            throw HttpException::validation(['file' => [$e->getMessage()]]);
        }
        if (!$this->scanner->isClean($content, $type['mime'])) {
            throw HttpException::validation(['file' => ['Fichier refusé par l\'analyse de sécurité (contenu actif détecté).']]);
        }

        $path = $this->files->put($tenantId, $personId, $type['ext'], $content);
        $id = (string) new Ulid();
        $this->store->insert('person_documents', [
            'id' => $id, 'tenant_id' => $tenantId, 'person_id' => $personId, 'kind' => $kind,
            'storage_path' => $path, 'size_bytes' => strlen($content), 'mime_type' => $type['mime'],
            'expires_on' => $expiresOn, 'uploaded_by' => $actorId, 'created_at' => $this->clock->now(),
        ]);
        if ($kind === DocumentKind::IdPhoto->value) {
            $this->store->update('people', $tenantId, $personId, ['photo_path' => $path]);
        }

        return $this->get($tenantId, $personId, $id);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $personId, string $documentId): array
    {
        $row = $this->store->find('person_documents', $tenantId, $documentId);
        if ($row === null || $row['person_id'] !== $personId) {
            throw HttpException::notFound('Pièce introuvable.');
        }

        return $row;
    }

    public function delete(string $tenantId, string $personId, string $documentId): void
    {
        $row = $this->get($tenantId, $personId, $documentId);
        $this->store->delete('person_documents', $tenantId, ['id' => $documentId]);
        $this->files->delete((string) $row['storage_path']);
    }

    /** @return list<array<string, mixed>> pièces avec leur état calculé (received, expiring_soon, expired) */
    public function forPerson(string $tenantId, string $personId): array
    {
        return array_map(
            fn (array $row): array => $row + ['status' => $this->status($row)],
            $this->store->where('person_documents', $tenantId, ['person_id' => $personId]),
        );
    }

    /**
     * Pièces attendues, reçues (valides), bientôt périmées, manquantes — pour l'écran « 5 / 7 ».
     *
     * @return array{required: int, received: int, expiring_soon: list<string>, expired: list<string>, missing: list<string>}
     */
    public function completeness(string $tenantId, string $personId): array
    {
        $valid = [];
        $expiring = [];
        $expired = [];
        foreach ($this->forPerson($tenantId, $personId) as $row) {
            $kind = (string) $row['kind'];
            if ($row['status'] === 'expired') {
                $expired[$kind] = $kind;
                continue;
            }
            $valid[$kind] = $kind;
            if ($row['status'] === 'expiring_soon') {
                $expiring[$kind] = $kind;
            }
        }
        $required = DocumentKind::required();
        $missing = array_values(array_diff($required, $valid));

        return [
            'required' => count($required),
            'received' => count($required) - count($missing),
            'expiring_soon' => array_values(array_intersect($required, $expiring)),
            'expired' => array_values(array_intersect($required, array_diff($expired, $valid))),
            'missing' => $missing,
        ];
    }

    /** @return array{url: string, expires_at: string} */
    public function signedLink(string $tenantId, string $personId, string $documentId): array
    {
        $this->get($tenantId, $personId, $documentId);
        $ttl = max(30, (int) $this->config->get('people.link_ttl', 300));
        $expires = $this->clock->timestamp() + $ttl;
        $prefix = (string) $this->config->get('app.api_prefix', '/api/v1');

        return [
            'url' => sprintf(
                '%s/people/%s/documents/%s/download?expires=%d&signature=%s',
                $prefix,
                $personId,
                $documentId,
                $expires,
                $this->signer->sign($personId . '|' . $documentId, $expires),
            ),
            'expires_at' => $this->clock->iso($expires),
        ];
    }

    /**
     * Téléchargement par URL signée : la signature et l'expiration sont vérifiées AVANT toute lecture en base.
     *
     * @return array{row: array<string, mixed>, content: string}
     */
    public function fetchSigned(string $personId, string $documentId, int $expires, string $signature): array
    {
        if (!$this->signer->verify($personId . '|' . $documentId, $expires, $signature, $this->clock->timestamp())) {
            throw HttpException::forbidden('Lien de téléchargement invalide ou expiré. Demandez un nouveau lien.');
        }
        $row = $this->store->findAnyTenant('person_documents', $documentId);
        if ($row === null || $row['person_id'] !== $personId) {
            throw HttpException::notFound('Pièce introuvable.');
        }
        $content = $this->files->read((string) $row['storage_path']) ?? throw HttpException::notFound('Fichier introuvable.');

        return ['row' => $row, 'content' => $content];
    }

    /** @param array<string, mixed> $row */
    private function status(array $row): string
    {
        $expiresOn = $row['expires_on'] ?? null;
        if (!is_string($expiresOn) || $expiresOn === '') {
            return 'received';
        }
        if ($expiresOn < $this->clock->today()) {
            return 'expired';
        }

        return $expiresOn <= $this->clock->inDays(self::EXPIRING_DAYS) ? 'expiring_soon' : 'received';
    }
}
