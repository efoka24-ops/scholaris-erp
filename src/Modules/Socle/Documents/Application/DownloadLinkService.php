<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\Documents\Infrastructure\DocumentRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\DownloadLinkRepository;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Liens de téléchargement à durée limitée (SS14) : jeton aléatoire de 192 bits (seule son empreinte SHA-256 est stockée), durée
 * `documents.download_link_ttl_hours` (défaut 24 h), nombre d'utilisations `documents.download_link_max_uses` (défaut 5).
 * Sans jeton valide, rien n'est exposé : 404 jeton inconnu, 410 lien expiré, épuisé ou document révoqué. Chaque tentative est
 * journalisée (IP hachée) ; la création est tracée dans l'audit.
 */
final class DownloadLinkService
{
    public const DEFAULT_TTL_HOURS = 24;
    public const MAX_TTL_HOURS = 720;
    public const DEFAULT_MAX_USES = 5;
    public const MAX_USES = 50;
    public const TOKEN_FORMAT = '/^[a-f0-9]{48}$/';
    public const NOT_FOUND = 'Lien de téléchargement inconnu.';

    public function __construct(
        private readonly DownloadLinkRepository $links,
        private readonly DocumentRepository $documents,
        private readonly DocumentService $service,
        private readonly DocumentClock $clock,
        private readonly SettingsReader $settings,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return array{url: string, token: string, expires_at: string, max_uses: int} */
    public function create(string $tenantId, string $establishmentId, string $documentId, string $createdBy): array
    {
        $document = $this->documents->find($tenantId, $documentId, $establishmentId) ?? throw HttpException::notFound('Document introuvable.');
        if ($document['status'] !== IssuedDocument::VALID) {
            throw HttpException::conflict('Seul un document valide peut être partagé par un lien de téléchargement.');
        }
        $ttl = $this->bounded($tenantId, $establishmentId, 'documents.download_link_ttl_hours', self::DEFAULT_TTL_HOURS, self::MAX_TTL_HOURS);
        $maxUses = $this->bounded($tenantId, $establishmentId, 'documents.download_link_max_uses', self::DEFAULT_MAX_USES, self::MAX_USES);
        $token = bin2hex(random_bytes(24));
        $now = $this->clock->moment();
        $expiresAt = $now->modify('+' . $ttl . ' hours')->format(DATE_ATOM);
        $id = (string) new Ulid();

        $this->links->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'document_id' => $documentId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses,
            'use_count' => 0,
            'created_by' => $createdBy,
            'created_at' => $now->format(DATE_ATOM),
        ]);
        $this->audit->append($tenantId, $createdBy, 'document.download_link.created', 'document', $documentId, null, [
            'link_id' => $id, 'expires_at' => $expiresAt, 'max_uses' => $maxUses,
        ]);

        return ['url' => $this->clock->downloadBase() . $token, 'token' => $token, 'expires_at' => $expiresAt, 'max_uses' => $maxUses];
    }

    /**
     * Ouvre un lien public : consomme une utilisation et renvoie le PDF du document.
     *
     * @return array{pdf: string, number: string}
     */
    public function open(string $token, ?string $ip): array
    {
        $now = $this->clock->moment();
        $link = preg_match(self::TOKEN_FORMAT, $token) === 1 ? $this->links->findByHash(hash('sha256', $token)) : null;
        if ($link === null) {
            $this->links->log((string) new Ulid(), '', null, 'unknown', $ip, $now->format(DATE_ATOM));

            throw HttpException::notFound(self::NOT_FOUND);
        }
        $tenantId = (string) $link['tenant_id'];
        $linkId = (string) $link['id'];
        $document = $this->documents->find($tenantId, (string) $link['document_id'], (string) $link['establishment_id']);
        $outcome = match (true) {
            new DateTimeImmutable((string) $link['expires_at']) <= $now => 'expired',
            $document === null || $document['status'] !== IssuedDocument::VALID => 'revoked',
            $this->links->consume($linkId, $now->format(DATE_ATOM)) === 0 => 'exhausted',
            default => 'served',
        };
        $this->links->log((string) new Ulid(), $tenantId, $linkId, $outcome, $ip, $now->format(DATE_ATOM));
        if ($outcome !== 'served') {
            throw self::gone($outcome);
        }

        return ['pdf' => $this->service->pdf((string) $document['content']), 'number' => (string) $document['number']];
    }

    private static function gone(string $outcome): HttpException
    {
        $detail = match ($outcome) {
            'expired' => 'Ce lien de téléchargement a expiré. Demandez un nouveau lien à l\'établissement.',
            'exhausted' => 'Ce lien de téléchargement a déjà été utilisé le nombre de fois permis.',
            default => 'Ce document a été remplacé ou annulé : le lien n\'est plus valable.',
        };

        return new HttpException(410, 'Lien expiré', $detail, 'https://api.scholaris.cm/errors/link-expired');
    }

    private function bounded(string $tenantId, string $establishmentId, string $key, int $default, int $max): int
    {
        try {
            $value = $this->settings->value($tenantId, $establishmentId, $key, $default);
        } catch (HttpException) {
            return $default;
        }

        return is_int($value) && $value >= 1 ? min($value, $max) : $default;
    }
}
