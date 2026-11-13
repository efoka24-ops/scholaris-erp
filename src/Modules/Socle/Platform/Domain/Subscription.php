<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Domain;

use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Règles d'abonnement d'un droit d'usage : essai gratuit, puis période payée ; l'échéance est le dernier jour couvert.
 * Le lendemain de l'échéance, l'abonnement est impayé ; au-delà du délai de grâce, il est suspendu.
 */
final class Subscription
{
    /** Paliers de relance avant l'échéance (jours restants). */
    public const REMINDER_STAGES = [14, 3, 0];

    private function __construct()
    {
    }

    /** Dernier jour d'essai : un essai de 90 jours commencé le 1er couvre du 1er au 90e jour inclus. */
    public static function trialEnd(string $startedOn, int $trialDays): string
    {
        return Calendar::addDays($startedOn, max(0, $trialDays - 1));
    }

    /** Dernier jour couvert (essai ou paiement). */
    public static function dueOn(?string $paidUntil, string $trialEndsOn): string
    {
        return $paidUntil === null ? $trialEndsOn : Calendar::max($paidUntil, $trialEndsOn);
    }

    /**
     * Palier de relance à envoyer aujourd'hui, ou null : le plus petit palier atteint (une exécution manquée la veille
     * ne fait pas perdre la relance, mais n'en envoie qu'une).
     *
     * @param list<int> $alreadySent paliers déjà envoyés pour cette échéance
     * @return array{stage: int, covered: list<int>}|null
     */
    public static function reminderFor(int $daysLeft, array $alreadySent): ?array
    {
        if ($daysLeft < 0) {
            return null;
        }
        $reached = array_values(array_filter(self::REMINDER_STAGES, static fn (int $stage): bool => $daysLeft <= $stage));
        if ($reached === [] || array_diff($reached, $alreadySent) === []) {
            return null;
        }

        return ['stage' => min($reached), 'covered' => $reached];
    }

    /**
     * Nouvel état d'après la date du jour (sans effet sur suspendu ou fermé, qui ne changent que par paiement ou décision).
     * Une promesse de paiement ($promiseUntil, date incluse) reporte la suspension : l'abonnement reste impayé jusque-là.
     */
    public static function nextState(string $state, string $today, string $dueOn, int $graceDays, ?string $promiseUntil = null): string
    {
        if (!in_array($state, [ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE], true)) {
            return $state;
        }
        if (strcmp($today, $dueOn) <= 0) {
            return $state;
        }

        if (Calendar::daysBetween($dueOn, $today) <= $graceDays || ($promiseUntil !== null && strcmp($today, $promiseUntil) <= 0)) {
            return ApplicationAccess::PAST_DUE;
        }

        return ApplicationAccess::SUSPENDED;
    }

    /**
     * Période couverte par un paiement de $months mois : à partir du lendemain de l'échéance si elle est à venir
     * (paiement anticipé, l'essai n'est pas perdu), sinon à partir d'aujourd'hui.
     *
     * @return array{from: string, until: string}
     */
    public static function coverage(string $today, string $dueOn, int $months): array
    {
        $from = strcmp($dueOn, $today) >= 0 ? Calendar::addDays($dueOn, 1) : $today;

        return ['from' => $from, 'until' => Calendar::addDays(Calendar::addMonths($from, $months), -1)];
    }
}
