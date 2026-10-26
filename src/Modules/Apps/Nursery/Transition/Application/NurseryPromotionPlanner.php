<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Transition\Application;

use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Scholaris\Modules\Socle\Organisation\Contracts\ConditionalPromotionPlanner;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * Passage à la clôture d'année d'une maternelle PRIVÉE (`organisation.closure.promotion.nursery`, EX-MAT-21, 37) : ne s'applique qu'à
 * un client qui a l'application `nursery` (un client `public_nursery` garde son propre planificateur). Pour chaque décision prise :
 * « primaire » clôt l'inscription (transfert) ; « section suivante » et « maintien » inscrivent l'enfant dans la section retenue pour
 * l'année suivante. Une décision appliquée est figée (idempotent). Les enfants sans décision reçoivent une proposition et sont
 * comptés : rien n'est décidé à la place de la direction. Un refus du socle (inscription vivante, garde) laisse la décision en attente.
 */
final class NurseryPromotionPlanner implements ConditionalPromotionPlanner
{
    public const CONTAINER_KEY = 'organisation.closure.promotion.nursery';

    public function __construct(
        private readonly TransitionService $transitions,
        private readonly SectionService $sections,
        private readonly ChildDirectory $children,
        private readonly LearnerWriter $learners,
        private readonly ApplicationAccess $applications,
        private readonly NurseryClock $clock,
    ) {
    }

    public function establishmentTypes(): array
    {
        return ['nursery'];
    }

    public function appliesTo(string $tenantId, string $establishmentId): bool
    {
        return $this->applications->allowsReads($tenantId, 'nursery');
    }

    public function promote(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult
    {
        if (!$this->applications->allowsWrites($tenantId, 'nursery')) {
            return new ClosureStepResult(ClosureStepResult::SKIPPED, 'Application Maternelle privée suspendue ou fermée : aucun passage appliqué.');
        }
        $perimeter = new Perimeter($tenantId, $establishmentId, true);
        foreach ($this->sections->inPerimeter($perimeter) as $section) {
            $this->transitions->propose(null, $perimeter, (string) $section['id']);
        }
        $counts = ['to_primary' => 0, 'enrolled' => 0, 'pending' => 0, 'undecided' => 0, 'already_applied' => 0];
        $today = $this->clock->today();
        foreach ($this->transitions->ofYear($tenantId, $establishmentId, $fromYearId) as $row) {
            $status = (string) $row['status'];
            if ($status === 'applied') {
                ++$counts['already_applied'];
                continue;
            }
            if ($status !== 'decided') {
                ++$counts['undecided'];
                continue;
            }
            $learnerId = (string) $row['learner_id'];
            try {
                if ($row['decision'] === TransitionService::TO_PRIMARY) {
                    $enrollment = $this->children->currentEnrollment($tenantId, $establishmentId, $learnerId);
                    if ($enrollment !== null && $enrollment->academicYearId === $fromYearId) {
                        $this->learners->closeEnrollment($tenantId, $enrollment->id, 'transferred', $today);
                    }
                    ++$counts['to_primary'];
                } else {
                    $this->learners->enroll($tenantId, $establishmentId, $learnerId, (string) $row['target_structure_id'], $toYearId, $today, null);
                    ++$counts['enrolled'];
                }
                $this->transitions->markApplied($tenantId, $establishmentId, (string) $row['id']);
            } catch (\Throwable) {
                ++$counts['pending'];
            }
        }
        $summary = sprintf(
            '%d vers le primaire, %d inscrit(s) pour l’année suivante, %d en attente (refus du socle), %d sans décision.',
            $counts['to_primary'],
            $counts['enrolled'],
            $counts['pending'],
            $counts['undecided'],
        );

        return new ClosureStepResult(ClosureStepResult::DONE, $summary, $counts);
    }
}
