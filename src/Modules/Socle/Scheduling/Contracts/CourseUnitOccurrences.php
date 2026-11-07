<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Contracts;

/**
 * Occurrences d'un élément constitutif (D13) selon l'emploi du temps : consommation des volumes CM/TD/TP et base des taux
 * d'assiduité. Bornes incluses (AAAA-MM-JJ), au plus 366 jours parcourus.
 */
interface CourseUnitOccurrences
{
    /** @return list<OccurrenceInfo> occurrences tenues (prévues, remplacées, déplacées vers la date), triées par date et heure */
    public function heldOf(string $tenantId, string $establishmentId, string $courseUnitId, string $from, string $to): array;

    /** @return list<OccurrenceInfo> occurrences prévues par l'emploi du temps, annulées et déplacées ailleurs comprises */
    public function scheduledOf(string $tenantId, string $establishmentId, string $courseUnitId, string $from, string $to): array;

    /** @return array<string, int> minutes tenues par nature de séance (cm, td, tp, other ; non précisée = other) */
    public function heldMinutesByKind(string $tenantId, string $establishmentId, string $courseUnitId, string $from, string $to): array;
}
