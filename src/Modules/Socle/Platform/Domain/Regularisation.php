<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Domain;

use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Mode régularisation de paiement : une application impayée est bloquée à l'un de deux niveaux, choisi par la plateforme
 * (réglage général, surcharge par client) : `read_only` (lecture et export, écritures refusées) ou `locked` (tout est bloqué
 * sauf l'écran de régularisation, la déconnexion et le paiement). Le blocage ne supprime jamais de données.
 */
final class Regularisation
{
    public const READ_ONLY = 'read_only';
    public const LOCKED = 'locked';
    public const LEVELS = [self::READ_ONLY, self::LOCKED];
    public const ORIGIN_AUTO = 'auto';
    public const ORIGIN_MANUAL = 'manual';
    /** Durée de la promesse accordée par défaut à un déblocage manuel qui serait aussitôt re-bloqué. */
    public const DEFAULT_UNLOCK_DAYS = 7;
    /** Plus long report de blocage accordé d'un coup. */
    public const MAX_PROMISE_DAYS = 90;
    /** Jours avant l'échéance du préavis « échéance proche ». */
    public const DUE_SOON_DAYS = 7;

    private function __construct()
    {
    }

    /** Niveau effectif : choix pour ce client, sinon réglage de la plateforme (0 = lecture seule, 1 = bloqué). */
    public static function level(?string $override, int $platformDefault): string
    {
        if ($override !== null && in_array($override, self::LEVELS, true)) {
            return $override;
        }

        return $platformDefault === 1 ? self::LOCKED : self::READ_ONLY;
    }

    /**
     * État et niveau de blocage effectifs d'une ligne de tenant_applications à la date du jour, sans attendre la commande
     * quotidienne. Le niveau n'existe que pour un état suspendu : posé à la main ou par la commande (colonne
     * `regularisation`), ou déduit d'un impayé arrivé à terme. Une suspension ordinaire n'a pas de niveau (lecture seule).
     *
     * @param array<string, mixed> $row
     * @return array{state: string, level: ?string}
     */
    public static function effective(array $row, string $today, int $graceDays, int $platformDefault): array
    {
        $stored = (string) $row['state'];
        $dueOn = Subscription::dueOn(self::text($row, 'paid_until'), (string) $row['trial_ends_on']);
        $state = Subscription::nextState($stored, $today, $dueOn, $graceDays, self::text($row, 'promise_until'));
        if ($state !== ApplicationAccess::SUSPENDED) {
            return ['state' => $state, 'level' => null];
        }
        if ($stored === ApplicationAccess::SUSPENDED) {
            $level = self::text($row, 'regularisation');

            return ['state' => $state, 'level' => $level !== null && in_array($level, self::LEVELS, true) ? $level : null];
        }

        return ['state' => $state, 'level' => self::level(self::text($row, 'lock_level'), $platformDefault)];
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
