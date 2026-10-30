<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Modules\Socle\Assessment\Infrastructure\Db;
use Scholaris\Modules\Socle\Portals\Contracts\GradingTodo;

/**
 * Saisies de notes à faire d'un enseignant (portail enseignant) : ses évaluations dont la saisie est ouverte et dont
 * la classe compte des élèves sans note (ni note, ni absence, ni appréciation). Toujours évaluable pour ce module.
 */
final class PendingGradingTodo implements GradingTodo
{
    public function __construct(
        private readonly Db $db,
        private readonly AssessmentSettings $settings,
        private readonly Roster $roster,
    ) {
    }

    public function pendingFor(string $tenantId, string $teacherPersonId): array
    {
        $rows = $this->db->all(
            'SELECT id, establishment_id, structure_id, title, status FROM assessments WHERE tenant_id = ? AND teacher_person_id = ? ORDER BY held_on, id',
            [$tenantId, $teacherPersonId],
        );
        $items = [];
        foreach ($rows as $row) {
            $establishmentId = (string) $row['establishment_id'];
            if (!in_array((string) $row['status'], $this->entryStates($tenantId, $establishmentId), true)) {
                continue;
            }
            $structureId = (string) $row['structure_id'];
            $entered = $this->db->int(
                'SELECT COUNT(*) FROM grades WHERE tenant_id = ? AND assessment_id = ? AND (score IS NOT NULL OR absence IS NOT NULL OR qualitative IS NOT NULL)',
                [$tenantId, (string) $row['id']],
            );
            $missing = count($this->roster->students($tenantId, $establishmentId, $structureId)) - $entered;
            if ($missing > 0) {
                $items[] = [
                    'assessment_id' => (string) $row['id'], 'label' => (string) $row['title'], 'establishment_id' => $establishmentId,
                    'structure_id' => $structureId, 'missing_grades' => $missing,
                ];
            }
        }

        return $items;
    }

    /**
     * États où la saisie est attendue : les états éditables, hors état initial (brouillon) sauf s'il est le seul.
     *
     * @return list<string>
     */
    private function entryStates(string $tenantId, string $establishmentId): array
    {
        $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId);
        $states = array_values(array_diff($lifecycle->editable, [$lifecycle->states[0]]));

        return $states === [] ? $lifecycle->editable : $states;
    }
}
