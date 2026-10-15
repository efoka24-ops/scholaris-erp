<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Apps\Higher\Support\WriteGate;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/**
 * Relances automatiques de saisie en retard (EX-NOT-08, NOT-11) : pour chaque évaluation encore en saisie dont la date limite est
 * passée, notification de chaque enseignant affecté à l'EC (et au groupe), au plus tous les `higher.grades.reminder_every_days`
 * jours ; dédoublonnage par évaluation et par jour. Un client dont l'application est suspendue ou fermée est sauté (WriteGate).
 */
final class GradingReminders
{
    public function __construct(
        private readonly Store $store,
        private readonly GradesSettings $settings,
        private readonly AssignmentWriter $assignments,
        private readonly StaffDirectory $staff,
        private readonly HigherNotifier $notifier,
        private readonly WriteGate $gate,
        private readonly GradesClock $clock,
    ) {
    }

    /** @return array{assessments: int, notifications: int, skipped_tenants: int} */
    public function run(?string $day = null): array
    {
        if ($day !== null) {
            $this->clock->freeze($day);
        }
        $today = $this->clock->today();
        $result = ['assessments' => 0, 'notifications' => 0, 'skipped_tenants' => 0];
        $skipped = [];
        foreach ($this->store->scopes(AssessmentService::TABLE) as [$tenantId, $establishmentId]) {
            if (!$this->gate->allowsWrites($tenantId)) {
                $skipped[$tenantId] = true;
                continue;
            }
            $every = $this->settings->reminderEveryDays($tenantId, $establishmentId);
            $teachers = null;
            foreach ($this->store->select(AssessmentService::TABLE, $tenantId, $establishmentId, [], 'entry_due_on, id', 5000, 'entry_due_on IS NOT NULL AND entry_due_on < ?', [$today]) as $row) {
                if (!$this->settings->lifecycle($tenantId, $establishmentId, (string) $row['school_id'])->isEditable((string) $row['status'])) {
                    continue;
                }
                if ($row['last_reminded_on'] !== null && (new \DateTimeImmutable((string) $row['last_reminded_on']))->modify('+' . $every . ' days')->format('Y-m-d') > $today) {
                    continue;
                }
                $teachers ??= $this->teacherPersons($tenantId, $establishmentId);
                $recipients = [];
                foreach ($this->assignments->forElement($tenantId, $establishmentId, (string) $row['element_id']) as $assignment) {
                    if ($assignment->status === 'active' && ($row['group_id'] === null || $assignment->groupRef === null || $assignment->groupRef === $row['group_id'])
                        && isset($teachers[$assignment->staffId])) {
                        $recipients[$teachers[$assignment->staffId]] = true;
                    }
                }
                foreach (array_keys($recipients) as $personId) {
                    $this->notifier->notify($tenantId, $establishmentId, 'NOT-11', (string) $personId, [
                        'label' => $row['element_code'] . ' - ' . $row['label'], 'due_on' => (string) $row['entry_due_on'], 'reference' => (string) $row['id'],
                    ], 'NOT-11:' . $row['id'] . ':' . $today);
                    ++$result['notifications'];
                }
                $this->store->update(AssessmentService::TABLE, $tenantId, $establishmentId, ['last_reminded_on' => $today], ['id' => $row['id']]);
                ++$result['assessments'];
            }
        }
        $result['skipped_tenants'] = count($skipped);

        return $result;
    }

    /** @return array<string, string> fiche du personnel => personne */
    private function teacherPersons(string $tenantId, string $establishmentId): array
    {
        $map = [];
        foreach ($this->staff->teacherIdsIn($tenantId, $establishmentId) as $personId) {
            $info = $this->staff->find($tenantId, $personId);
            if ($info !== null) {
                $map[$info->staffId ?? $info->personId] = $info->personId;
            }
        }

        return $map;
    }
}
