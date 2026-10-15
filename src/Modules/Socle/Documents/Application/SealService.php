<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Infrastructure\SealRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\SignerRepository;

/** Signature électronique simple : signataire, image de signature et cachet de l'établissement (images PNG, JPEG ou WebP en data URI). */
final class SealService
{
    private const MAX_IMAGE = 300000;

    public function __construct(
        private readonly SealRepository $seals,
        private readonly AuditLog $audit,
        private readonly DocumentClock $clock,
        private readonly SignerRepository $signers,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function get(string $tenantId, string $establishmentId, ?string $signerRole = null, ?string $orgUnitId = null): ?array
    {
        if ($signerRole !== null) {
            return $this->signers->find($tenantId, $establishmentId, $signerRole, $orgUnitId ?? '');
        }

        return $this->seals->find($tenantId, $establishmentId);
    }

    /**
     * @param array<string, mixed> $input signer_name, signer_title?, signature_image?, stamp_image?
     * @return array<string, mixed>
     */
    public function put(string $tenantId, string $establishmentId, string $actor, array $input, ?string $signerRole = null, ?string $orgUnitId = null): array
    {
        $errors = [];
        foreach (['signature_image', 'stamp_image'] as $key) {
            $value = $input[$key] ?? null;
            if ($value !== null && (!is_string($value) || strlen($value) > self::MAX_IMAGE
                || preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/]+={0,2}$#', $value) !== 1)) {
                $errors[$key] = ['Image PNG, JPEG ou WebP en data URI base64 attendue (300 Ko au plus).'];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $values = [
            'signer_name' => (string) $input['signer_name'],
            'signer_title' => isset($input['signer_title']) ? (string) $input['signer_title'] : null,
            'signature_image' => isset($input['signature_image']) ? (string) $input['signature_image'] : null,
            'stamp_image' => isset($input['stamp_image']) ? (string) $input['stamp_image'] : null,
            'updated_at' => $this->clock->now(),
        ];
        if ($signerRole !== null) {
            // Signataire par rôle (S17), pour l'établissement ou une école interne.
            $this->signers->save($tenantId, $establishmentId, $signerRole, $orgUnitId ?? '', $values);
            $this->audit->append($tenantId, $actor, 'document_signer.updated', 'document_signer', $establishmentId, null, ['signer_role' => $signerRole, 'org_unit_id' => $orgUnitId, 'signer_name' => $input['signer_name']]);

            return $this->signers->find($tenantId, $establishmentId, $signerRole, $orgUnitId ?? '') ?? [];
        }
        $this->seals->save($tenantId, $establishmentId, $values);
        $this->audit->append($tenantId, $actor, 'document_seal.updated', 'document_seal', $establishmentId, null, ['signer_name' => $input['signer_name']]);

        return $this->seals->find($tenantId, $establishmentId) ?? [];
    }
}
