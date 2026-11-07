<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Resources;

/** Représentations JSON des ressources du module (listes blanches de champs). */
final class Resources
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function document(array $row, string $verificationBase): array
    {
        $out = Whitelist::pick($row, [
            'id', 'type_code', 'category', 'number', 'status', 'holder_name', 'subject_person_id', 'locale', 'template_version',
            'fingerprint', 'issued_by', 'issued_via', 'issued_at', 'signed_at', 'revoke_reason', 'revoked_at', 'revoked_by', 'replaced_by',
        ]);
        $out['verification_url'] = $verificationBase . (string) $row['verification_token'];
        $out['verification_token'] = $row['verification_token'];

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function type(array $row): array
    {
        $out = Whitelist::pick($row, ['id', 'code', 'label', 'category', 'type_letter', 'number_template', 'qr_enabled', 'blockable', 'variables', 'system', 'created_at', 'updated_at']);

        // Signataire par type (S17) : exposé seulement s'il est défini (réponse du socle inchangée sinon).
        return ($row['signer_role'] ?? null) !== null ? $out + ['signer_role' => (string) $row['signer_role']] : $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function template(array $row): array
    {
        $out = Whitelist::pick($row, ['id', 'type_code', 'locale', 'html', 'version', 'validated_at', 'created_by', 'created_at']);
        $out['system'] = $row['tenant_id'] === '*';

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function batch(array $row): array
    {
        $out = Whitelist::pick($row, ['id', 'type_code', 'locale', 'status', 'total', 'processed', 'failed', 'excluded', 'created_by', 'created_at', 'finished_at']);
        foreach (['total', 'processed', 'failed', 'excluded'] as $key) {
            $out[$key] = (int) $out[$key];
        }
        $out['progress'] = ['processed' => $out['processed'], 'total' => $out['total'], 'percent' => $out['total'] === 0 ? 100 : intdiv($out['processed'] * 100, $out['total'])];

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function batchItem(array $row): array
    {
        return Whitelist::pick($row, ['subject_person_id', 'status', 'reason', 'document_id']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function request(array $row): array
    {
        return Whitelist::pick($row, ['id', 'type_code', 'subject_person_id', 'requested_by', 'note', 'status', 'document_id', 'decided_by', 'decided_at', 'created_at']);
    }

    /**
     * Signataire par rôle (S17), sans les images.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    public static function signer(?array $row, string $role, ?string $orgUnitId): array
    {
        return ['signer_role' => $role, 'org_unit_id' => $orgUnitId] + self::seal($row);
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    public static function seal(?array $row): array
    {
        if ($row === null) {
            return ['configured' => false, 'signer_name' => null, 'signer_title' => null, 'has_signature' => false, 'has_stamp' => false, 'updated_at' => null];
        }

        return [
            'configured' => true,
            'signer_name' => $row['signer_name'],
            'signer_title' => $row['signer_title'],
            'has_signature' => $row['signature_image'] !== null,
            'has_stamp' => $row['stamp_image'] !== null,
            'updated_at' => $row['updated_at'],
        ];
    }
}
