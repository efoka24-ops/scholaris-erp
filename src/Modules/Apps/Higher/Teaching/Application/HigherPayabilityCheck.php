<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Application;

use DI\Container;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\GradingCompleteness;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Staff\Contracts\PayableHour;
use Scholaris\Modules\Socle\Staff\Contracts\PayableStatement;
use Scholaris\Modules\Socle\Staff\Contracts\VacationPayabilityCheck;

/**
 * Conditions de payabilité de l'IPES (D16), appelées par le socle avant la validation d'une heure et l'approbation d'un relevé :
 *  - RG-ENS-1 : heure payable seulement si la séance est consignée au cahier de textes, signée par l'enseignant et contre-signée ;
 *  - RG-ENS-4 : minutes au-delà du volume affecté payées seulement avec l'accord écrit du directeur d'école ;
 *  - RG-ENS-5 : solde du relevé retenu tant que la saisie des notes de l'EC est incomplète (`higher.vacation.hold_until_grades`, actif).
 * Une heure qui ne relève d'aucune affectation de l'IPES (autre application) n'est pas concernée.
 */
final class HigherPayabilityCheck implements VacationPayabilityCheck
{
    private const COUNTERSIGNED = ['countersigned', 'validated'];

    public function __construct(
        private readonly TeachingStore $store,
        private readonly AssignmentService $assignments,
        private readonly SettingsReader $settings,
        private readonly Container $container,
    ) {
    }

    public function hourRefusals(PayableHour $hour, string $validatorId): array
    {
        [$log, $assignment] = $this->locate($hour);
        if ($assignment === null) {
            return [];
        }
        $refusals = [];
        if ($log === null) {
            return ['RG-ENS-1 : séance absente du cahier de textes.'];
        }
        if ($log['teacher_signed_at'] === null) {
            $refusals[] = 'RG-ENS-1 : séance non émargée par l\'enseignant.';
        }
        if (!in_array($log['status'], self::COUNTERSIGNED, true)) {
            $refusals[] = 'RG-ENS-1 : séance non contre-signée par le délégué ou le surveillant.';
        }
        if (trim((string) $log['content']) === '') {
            $refusals[] = 'RG-ENS-1 : cahier de textes non renseigné.';
        }
        $done = 0;
        foreach ($this->store->select(SessionLogService::TABLE, $hour->tenantId, $hour->establishmentId, ['assignment_id' => (string) $assignment['id']], 'held_on, start_time', 5000) as $other) {
            if (in_array($other['status'], self::COUNTERSIGNED, true) && ((string) $other['held_on'] < (string) $log['held_on'] || $other['id'] === $log['id'])) {
                $done += (int) $other['minutes'];
            }
        }
        $allowed = (int) $assignment['planned_minutes'] + $this->assignments->approvedOverrun($hour->tenantId, $hour->establishmentId, (string) $assignment['id']);
        if ($done > $allowed) {
            $refusals[] = sprintf('RG-ENS-4 : %d minutes au-delà du volume affecté (%d) sans accord écrit du directeur d\'école.', $done - $allowed, (int) $assignment['planned_minutes']);
        }

        return $refusals;
    }

    public function statementRefusals(PayableStatement $statement, string $approverId): array
    {
        if ($this->settings->value($statement->tenantId, $statement->establishmentId, TeachingPermissions::HOLD_UNTIL_GRADES, true) === false) {
            return [];
        }
        $refusals = [];
        $seen = [];
        foreach ($statement->lines as $line) {
            [, $assignment] = $this->locate($line);
            if ($assignment === null) {
                continue;
            }
            $key = $assignment['element_id'] . '|' . $assignment['group_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($this->gradingComplete($statement->tenantId, $statement->establishmentId, (string) $assignment['element_id'], (string) $assignment['group_id']) === false) {
                $refusals[] = sprintf('RG-ENS-5 : solde retenu, la saisie des notes de l\'EC %s (groupe %s) est incomplète.', $assignment['element_code'], $assignment['group_id']);
            }
        }

        return $refusals;
    }

    /** @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null} séance et affectation IPES de l'heure */
    private function locate(PayableHour $hour): array
    {
        $tenantId = $hour->tenantId;
        $establishmentId = $hour->establishmentId;
        $log = $this->store->select(SessionLogService::TABLE, $tenantId, $establishmentId, ['vacation_hour_id' => $hour->id], 'id', 1)[0] ?? null;
        // Heure présentée par l'état des vacations IPES : identifiant de la séance elle-même (séance sans créneau ni heure du socle).
        $log ??= $this->store->find(SessionLogService::TABLE, $tenantId, $establishmentId, $hour->id);
        if ($log === null && $hour->slotId !== null) {
            $log = $this->store->select(SessionLogService::TABLE, $tenantId, $establishmentId, ['slot_id' => $hour->slotId, 'held_on' => $hour->heldOn], 'id', 1)[0] ?? null;
        }
        $assignment = null;
        if ($log !== null) {
            $assignment = $this->store->find(AssignmentService::TABLE, $tenantId, $establishmentId, (string) $log['assignment_id']);
        } elseif ($hour->assignmentId !== null) {
            $assignment = $this->store->select(AssignmentService::TABLE, $tenantId, $establishmentId, ['staff_assignment_id' => $hour->assignmentId], 'id', 1)[0] ?? null;
        }
        if ($assignment === null && $hour->slotId !== null) {
            $entry = $this->store->select(TimetableService::ENTRIES, $tenantId, $establishmentId, ['slot_id' => $hour->slotId], 'id', 1)[0] ?? null;
            $assignment = $entry === null ? null : $this->store->find(AssignmentService::TABLE, $tenantId, $establishmentId, (string) $entry['assignment_id']);
        }

        return [$log, $assignment];
    }

    private function gradingComplete(string $tenantId, string $establishmentId, string $elementId, string $groupId): ?bool
    {
        $result = null;
        foreach ($this->container->getKnownEntryNames() as $name) {
            if (!str_starts_with($name, GradingCompleteness::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $provider = is_string($entry) ? $this->container->get($entry) : $entry;
                if (!$provider instanceof GradingCompleteness) {
                    continue;
                }
                $complete = $provider->isComplete($tenantId, $establishmentId, $elementId, $groupId);
                if ($complete === false) {
                    return false;
                }
                $result = $complete ?? $result;
            }
        }

        return $result;
    }
}
