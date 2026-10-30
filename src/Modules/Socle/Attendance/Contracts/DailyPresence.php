<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/**
 * Absents du jour, par structure et par demi-journée, lus dans les appels validés (même règle que `AbsenteeDirectory`).
 * Les deux modes d'appel sont couverts, séance par séance (la lecture ne dépend pas du mode courant de l'établissement) :
 * - mode `day_part` : le moment porté par la séance (`morning`, `afternoon`) ; une séance `full_day` compte dans les deux demi-journées ;
 * - mode `slot` : la demi-journée vient de l'heure de début du créneau, matin avant `attendance.afternoon_starts_at`
 *   (défaut 12:00, à confirmer), après-midi à partir de cette heure.
 */
interface DailyPresence
{
    public const MORNING = 'morning';
    public const AFTERNOON = 'afternoon';

    /**
     * @param string|null $structureId classe ou section ; null = toutes les structures de l'établissement
     * @param string|null $dayPart `morning` ou `afternoon` ; null = absent à au moins un appel validé du jour
     * @return list<string> identifiants des personnes absentes (statut A), sans doublon, triés
     *
     * @throws \InvalidArgumentException demi-journée inconnue
     */
    public function absentOn(string $tenantId, string $establishmentId, string $date, ?string $structureId = null, ?string $dayPart = null): array;
}
