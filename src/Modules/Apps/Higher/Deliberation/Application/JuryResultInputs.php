<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherLmdStrategy;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultInputs;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultInputSource;

/**
 * Notes IPES de la séance du socle (C110-I1), enregistrée sous `assessment.result_input_sources.higher_deliberation` : pour un
 * groupe qui a un jury déclaré (structure, période, session), la séance (/deliberations), le PV et la publication (/results/publish)
 * calculent sur les notes des EC (MarkSources, comme la simulation du jury) avec les règles du jury (RulesProvider) et ses points
 * de jury. Sans jury, null : le socle garde ses propres épreuves.
 */
final class JuryResultInputs implements ResultInputSource
{
    public function __construct(
        private readonly Store $store,
        private readonly MarkSources $marks,
        private readonly RulesProvider $rules,
    ) {
    }

    public function inputs(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session): ?ResultInputs
    {
        $jury = $this->store->select(JuryService::TABLE, $tenantId, $establishmentId, [
            'structure_id' => $structureId, 'period_id' => $periodId, 'exam_session' => $session,
        ], 'id', 1)[0] ?? null;
        if ($jury === null) {
            return null;
        }
        $year = (string) $jury['academic_year_id'];
        $sheet = $this->marks->sheet($tenantId, $establishmentId, $structureId, $periodId, $session, $year);
        $options = [];
        foreach ($this->store->select('hi_jury_points', $tenantId, $establishmentId, ['jury_id' => (string) $jury['id']], 'person_id') as $point) {
            $options[(string) $point['person_id']] = [HigherLmdStrategy::JURY_POINTS_OPTION => (float) $point['points_granted']];
        }

        return new ResultInputs($sheet->units ?? [], $year, $this->rules->forJury($jury), $options);
    }
}
