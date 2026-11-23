<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/**
 * Calcul des vacations tel que l'établissement le paramètre (D14, D15) : coefficients d'équivalent TD lus dans les paramètres
 * `staff.hetd.cm|td|tp|other` (défauts 1,5 ; 1 ; 0,75 ; 1) et taux horaire par catégorie (grade) et niveau.
 */
interface VacationCalculator
{
    /** @return array{cm: int, td: int, tp: int, other: int} coefficients en millièmes (1,5 = 1 500) */
    public function coefficients(string $tenantId, string $establishmentId): array;

    /**
     * @param array<string, int> $minutesByKind minutes par nature (cm, td, tp, other)
     * @return int équivalent TD en millièmes d'heure (43,5 HETD = 43 500)
     */
    public function hetdMilli(string $tenantId, string $establishmentId, array $minutesByKind): int;

    /** Montant brut entier en XAF = HETD × taux, arrondi au franc. */
    public function amount(int $hetdMilli, int $hourlyRate): int;

    /** Taux horaire (XAF par HETD) de la catégorie pour ce niveau, sinon celui de la catégorie tous niveaux ; null si aucun. */
    public function hourlyRate(string $tenantId, string $establishmentId, string $category, ?string $level = null): ?int;
}
