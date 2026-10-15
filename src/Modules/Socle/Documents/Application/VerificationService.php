<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\Documents\Domain\VerificationToken;
use Scholaris\Modules\Socle\Documents\Infrastructure\DocumentRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\TypeRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\VerificationLogRepository;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Vérification publique d'un document par son jeton. Ne renvoie jamais de note ni de donnée personnelle
 * au-delà du titulaire masqué. Chaque consultation est journalisée (IP hachée).
 */
final class VerificationService
{
    public const STATE_VALID = 'valid';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_REPLACED = 'replaced';
    public const NOT_FOUND = 'Ce document n\'a pas été émis par SCHOLARIS.';

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly TypeRepository $types,
        private readonly EstablishmentDirectory $establishments,
        private readonly VerificationLogRepository $log,
        private readonly DocumentClock $clock,
        private readonly SettingsReader $settings,
    ) {
    }

    /**
     * @return array<string, scalar|null>
     * @throws HttpException 404 si le jeton est inconnu
     */
    public function verify(string $token, ?string $ip): array
    {
        $document = VerificationToken::isWellFormed($token) ? $this->documents->findByToken($token) : null;
        if ($document === null) {
            $this->log->record((string) new Ulid(), '', $token, 'unknown', $ip, $this->clock->now());

            throw HttpException::notFound(self::NOT_FOUND);
        }

        $tenantId = (string) $document['tenant_id'];
        $revoked = $document['status'] === IssuedDocument::REVOKED;
        $this->log->record((string) new Ulid(), $tenantId, $token, $revoked ? 'revoked' : 'valid', $ip, $this->clock->now());

        $establishment = $this->establishments->find($tenantId, (string) $document['establishment_id']);
        $type = $this->types->find($tenantId, (string) $document['establishment_id'], (string) $document['type_code']);
        $fingerprint = (string) $document['fingerprint'];

        $out = [
            'status' => $revoked ? IssuedDocument::REVOKED : IssuedDocument::VALID,
            'type' => (string) $document['type_code'],
            'type_label' => $type !== null ? (string) $type['label'] : (string) $document['type_code'],
            'establishment' => $establishment?->name,
            'holder' => (string) $document['holder_mask'],
            'year' => (int) substr((string) $document['issued_at'], 0, 4),
            'number' => (string) $document['number'],
            'issued_on' => substr((string) $document['issued_at'], 0, 10),
            'fingerprint' => substr($fingerprint, 0, 4) . '…' . substr($fingerprint, -4),
        ];
        if ($revoked) {
            $out['revoked_on'] = substr((string) $document['revoked_at'], 0, 10);
            $out['revoke_reason'] = (string) $document['revoke_reason'];
            $replacement = $document['replaced_by'] !== null ? $this->documents->find($tenantId, (string) $document['replaced_by']) : null;
            $out['replaced_by_number'] = $replacement !== null ? (string) $replacement['number'] : null;
        }

        if ($this->detailedStatus($tenantId, (string) $document['establishment_id'])) {
            // État distinct de la page publique (S19) : valide, annulé, ou remplacé par un autre document.
            $out['state'] = !$revoked ? self::STATE_VALID : ($document['replaced_by'] !== null ? self::STATE_REPLACED : self::STATE_CANCELLED);
        }

        return $out;
    }

    /** Paramètre `documents.verification.detailed_status` (faux par défaut : réponse du socle inchangée). */
    private function detailedStatus(string $tenantId, string $establishmentId): bool
    {
        try {
            return $this->settings->value($tenantId, $establishmentId, 'documents.verification.detailed_status', false) === true;
        } catch (HttpException) {
            return false;
        }
    }
}
