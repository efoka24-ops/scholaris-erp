<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Contracts;

/**
 * Émission des bulletins par conseil (contrat de LECTURE interne, fourni par Councils, ajouté pour les tableaux de bord E4-A) : pour
 * chaque classe et période demandées, date du premier instantané de bulletin enregistré (`se_report_snapshots`) et nombre d'élèves
 * couverts. Aucune donnée nominative. Toujours borné au client et à l'établissement.
 */
interface ReportCardIssuance
{
    /**
     * @param list<string> $periodIds périodes (trimestres) demandées
     * @return list<array{structure_id: string, period_id: string, first_issued_at: string, students: int}>
     */
    public function issuedFor(string $tenantId, string $establishmentId, array $periodIds): array;
}
