<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku;

/**
 * Vérification et lecture d'un webhook Sungku.
 *
 * Signature : « sha256= » + HMAC-SHA256 de « <horodatage>.<corps brut> » avec le secret du projet, comparée en
 * temps constant, à partir des en-têtes X-Apisungku-Timestamp et X-Apisungku-Signature. Le corps brut doit être
 * celui reçu, octet pour octet (pas un JSON ré-encodé).
 *
 * Statuts : Sungku envoie un vocabulaire normalisé dont « CONFIRMED » pour un paiement abouti (et non « COMPLETED »).
 * Tout statut inconnu reste « pending » : on ne suppose jamais un échec ni un succès qu'on ne peut pas prouver.
 * À CONFIRMER avec un vrai webhook de test de Sungku avant la mise en production.
 */
final class SungkuWebhook
{
    public const CONFIRMED = 'confirmed';
    public const FAILED = 'failed';
    public const PENDING = 'pending';

    private const CONFIRMED_STATUSES = ['CONFIRMED'];
    private const FAILED_STATUSES = ['FAILED', 'REJECTED', 'CANCELLED', 'CANCELED', 'EXPIRED'];

    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 600,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function verify(string $rawBody, string $timestamp, string $signature): bool
    {
        if ($this->secret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        return $this->fresh($timestamp);
    }

    /** Statut interne (confirmed, failed, pending) à partir du statut Sungku. */
    public static function internalStatus(string $sungkuStatus): string
    {
        $status = strtoupper(trim($sungkuStatus));

        return match (true) {
            in_array($status, self::CONFIRMED_STATUSES, true) => self::CONFIRMED,
            in_array($status, self::FAILED_STATUSES, true) => self::FAILED,
            default => self::PENDING,
        };
    }

    /** Horodatage en secondes ou millisecondes Unix ; un horodatage non numérique n'est pas contrôlé en âge. */
    private function fresh(string $timestamp): bool
    {
        if (!ctype_digit($timestamp)) {
            return true;
        }

        $value = (int) $timestamp;
        if ($value > 99_999_999_999) {
            $value = intdiv($value, 1000);
        }
        $now = $this->clock !== null ? ($this->clock)() : time();

        return abs($now - $value) <= $this->toleranceSeconds;
    }
}
