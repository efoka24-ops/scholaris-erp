<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Domain;

/**
 * Signature d'un lot : HMAC-SHA256 en hexadécimal de `identifiant de l'appareil, LF, identifiant du lot, LF, corps compressé
 * tel qu'envoyé`, avec la clé de l'appareil. L'appareil et le lot sont liés au corps : une signature ne sert ni pour un autre
 * lot, ni pour un autre appareil. L'en-tête X-Batch-Signature porte 64 caractères hexadécimaux, avec ou sans le préfixe `sha256=`.
 */
final class BatchSignature
{
    public static function compute(string $key, string $deviceId, string $batchId, string $body): string
    {
        return hash_hmac('sha256', $deviceId . "\n" . $batchId . "\n" . $body, $key);
    }

    /** Vérifie l'en-tête reçu ; comparaison à temps constant, format strict, clé vide refusée. */
    public static function matches(string $key, string $deviceId, string $batchId, string $body, string $header): bool
    {
        $provided = strtolower(trim($header));
        if (str_starts_with($provided, 'sha256=')) {
            $provided = substr($provided, 7);
        }
        if ($key === '' || preg_match('/^[0-9a-f]{64}$/D', $provided) !== 1) {
            return false;
        }

        return hash_equals(self::compute($key, $deviceId, $batchId, $body), $provided);
    }
}
