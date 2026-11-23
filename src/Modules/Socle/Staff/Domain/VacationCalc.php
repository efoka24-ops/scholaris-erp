<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Domain;

/** Calcul simple d'une vacation : minutes par nature de séance, équivalent TD, montant brut entier en XAF. Aucune retenue. */
final class VacationCalc
{
    /**
     * @param array<string, int> $minutesByKind minutes réalisées par nature de séance (cm, td, tp, other)
     * @param array<string, int> $coefficients coefficients en millièmes par nature ; une nature absente garde son défaut (SessionKind)
     * @return int équivalent TD en millièmes d'heure (43,5 HETD = 43 500)
     */
    public static function hetdMilli(array $minutesByKind, array $coefficients = []): int
    {
        $total = 0;
        foreach ($minutesByKind as $kind => $minutes) {
            $sessionKind = SessionKind::tryFrom((string) $kind) ?? SessionKind::Other;
            $coefficient = $coefficients[$sessionKind->value] ?? $sessionKind->hetdPermille();
            $total += intdiv(max(0, $minutes) * $coefficient + 30, 60);
        }

        return $total;
    }

    /** Montant brut = HETD × taux, arrondi au franc le plus proche. */
    public static function amount(int $hetdMilli, int $hourlyRate): int
    {
        return intdiv($hetdMilli * $hourlyRate + 500, 1000);
    }

    /** @return array{0: string, 1: string} premier et dernier jour du mois « AAAA-MM » */
    public static function monthBounds(string $period): array
    {
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
        if ($first === false || $first->format('Y-m') !== $period) {
            throw new \InvalidArgumentException('La période doit être au format AAAA-MM.');
        }

        return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }
}
