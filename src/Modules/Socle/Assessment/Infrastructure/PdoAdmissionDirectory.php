<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Modules\Socle\Assessment\Application\RuleService;
use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;

/**
 * Admissions de l'année lues dans les résultats : les périodes de l'année sont celles de ses évaluations ; pour chaque
 * personne on retient la décision la plus récente (session de rattrapage d'abord, puis date de calcul, puis période),
 * les résultats sans décision étant ignorés. Une décision vaut admission si son étiquette est déclarée « passing »
 * (par défaut : Admis, Admis par compensation).
 */
final class PdoAdmissionDirectory implements AdmissionDirectory
{
    public function __construct(
        private readonly Db $db,
        private readonly RuleService $rules,
    ) {
    }

    public function admittedPersonIds(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        $rows = $this->db->all(
            "SELECT person_id, decision FROM results
             WHERE tenant_id = ? AND establishment_id = ? AND decision IS NOT NULL
               AND period_id IN (SELECT period_id FROM assessments WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ?)
             ORDER BY CASE WHEN exam_session = 'resit' THEN 1 ELSE 0 END DESC, computed_at DESC, period_id DESC, id DESC",
            [$tenantId, $establishmentId, $tenantId, $establishmentId, $academicYearId],
        );
        $latest = [];
        foreach ($rows as $row) {
            $latest[(string) $row['person_id']] ??= (string) $row['decision'];
        }
        $passing = $this->rules->passingLabels($tenantId, $establishmentId, $academicYearId);

        return array_keys(array_filter($latest, static fn (string $decision): bool => in_array($decision, $passing, true)));
    }
}
