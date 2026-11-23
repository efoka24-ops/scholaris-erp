<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Application;

use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\SecondaryStrategy;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\WorkProposals;
use Scholaris\Modules\Apps\Secondary\Discipline\Contracts\ConductRecord;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Workshops\Contracts\PracticalMarks;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilLineProvider;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleCalculator;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodTree;

/**
 * Pré-remplissage des lignes du conseil de classe du secondaire (T902, `assessment.council_line_providers.secondary`), appelé par le
 * socle à l'ouverture et à chaque recalcul, juste après le calcul des résultats :
 *  - conduite : sanctions du barème APPLIQUÉES sur la période (Discipline\Contracts\ConductRecord), la plus récente en clair ;
 *  - heures d'absence (justifiées, non justifiées, retards) : séances validées du socle (AttendanceTotals), une séance = une heure ;
 *  - reprise des propositions calculées par la stratégie (`computed_extras` des résultats enregistrés) : proposition du travail,
 *    passage, matières en alerte, pratique sous le seuil, matières non évaluées ;
 *  - distinction refusée si `distinction_blocked_by_conduct` et une sanction de conduite appliquée sur la période.
 * Ce ne sont que des propositions : le socle ne remplace jamais une valeur saisie en séance. Notes pratiques d'atelier (C-117) : lues
 * par le contrat Workshops\Contracts\PracticalMarks (séances de la classe sur la période, « points / base »), nul sans évaluation.
 * Hors d'une classe du secondaire (ou d'une version de règles d'une autre stratégie), le fournisseur ne répond rien.
 */
final class SecondaryCouncilLines implements CouncilLineProvider
{
    public function __construct(
        private readonly ClassProfiles $classes,
        private readonly PeriodTree $periods,
        private readonly RuleCalculator $calculator,
        private readonly CouncilDirectory $councils,
        private readonly AttendanceTotals $attendance,
        private readonly ConductRecord $conduct,
        private readonly PracticalMarks $practical,
    ) {
    }

    public function prefill(string $tenantId, string $establishmentId, string $structureId, string $periodId, array $personIds): array
    {
        if ($personIds === [] || $this->classes->find($tenantId, $establishmentId, $structureId) === null) {
            return [];
        }
        $period = $this->periods->find($tenantId, $establishmentId, $periodId);
        if ($period === null) {
            return [];
        }
        $rules = $this->calculator->rulesFor($tenantId, $establishmentId, $period->academicYearId, $structureId);
        if ($rules->strategy !== SecondaryStrategy::KEY) {
            return [];
        }
        $from = substr($period->startsOn, 0, 10);
        $to = substr($period->endsOn, 0, 10);
        $stored = $this->councils->storedResults($tenantId, $establishmentId, $structureId, $periodId);
        $totals = $this->attendance->byLearner($tenantId, $establishmentId, $from, $to);
        $practical = $this->practical->forClass($tenantId, $establishmentId, $structureId, $from, $to);
        $out = [];
        foreach ($personIds as $personId) {
            $extras = $stored[$personId]['extras'] ?? [];
            $sanctions = [];
            foreach ($this->conduct->appliedFor($tenantId, $establishmentId, $personId, $from, $to) as $sanction) {
                $sanctions[] = ['code' => $sanction['code'], 'label' => $sanction['label']];
            }
            $work = is_array($extras['work_proposal'] ?? null) ? $extras['work_proposal'] : null;
            $refused = $work !== null && ($work['kind'] ?? null) === WorkProposals::DISTINCTION && $sanctions !== []
                && ($rules->options[WorkProposals::OPTION_BLOCKED] ?? false) === true;
            $summary = $totals[$personId] ?? null;
            $hours = $summary === null ? ['total' => 0.0, 'justified' => 0.0, 'unjustified' => 0.0, 'lates' => 0]
                : [
                    'total' => $summary->absences * Defaults::HOURS_PER_SESSION,
                    'justified' => max(0, $summary->absences - $summary->unjustifiedAbsences) * Defaults::HOURS_PER_SESSION,
                    'unjustified' => $summary->unjustifiedAbsences * Defaults::HOURS_PER_SESSION,
                    'lates' => $summary->lates,
                ];
            $line = [
                'extras' => [
                    'work_proposal' => $refused ? null : $work,
                    'distinction_refused' => $refused,
                    'refused_proposal' => $refused ? $work : null,
                    'conduct_sanctions' => $sanctions,
                    'absence_hours' => $hours,
                    'promotion' => is_array($extras['promotion'] ?? null) ? $extras['promotion'] : null,
                    'subject_alerts' => self::ids($extras['subject_alerts'] ?? null),
                    'practical_alerts' => self::ids($extras['practical_alerts'] ?? null),
                    'not_evaluated' => self::ids($extras['not_evaluated'] ?? null),
                    'workshop_practical' => $practical[$personId] ?? null,
                ],
            ];
            if ($sanctions !== []) {
                $line['conduct'] = $sanctions[count($sanctions) - 1]['label'];
            }
            $out[$personId] = $line;
        }

        return $out;
    }

    /** @return list<string> */
    private static function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
    }
}
