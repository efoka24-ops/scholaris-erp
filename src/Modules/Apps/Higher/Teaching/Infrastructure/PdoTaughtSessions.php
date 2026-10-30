<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Infrastructure;

use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TaughtSessionInfo;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TaughtSessions;

/** Lecture des séances (hi_session_logs, hi_assignments) pour les autres sous-modules Higher. */
final class PdoTaughtSessions implements TaughtSessions
{
    private const LOGS = 'hi_session_logs';
    private const ASSIGNMENTS = 'hi_assignments';

    public function __construct(private readonly TeachingStore $store)
    {
    }

    public function ofTeacherInMonth(string $tenantId, string $establishmentId, string $personId, string $month): array
    {
        [$from, $to] = self::bounds($month);
        $assignments = [];
        $out = [];
        foreach ($this->store->select(self::LOGS, $tenantId, $establishmentId, ['person_id' => $personId], 'held_on, start_time', 5000, 'held_on >= ? AND held_on < ?', [$from, $to]) as $log) {
            $assignmentId = (string) $log['assignment_id'];
            $assignments[$assignmentId] ??= $this->store->find(self::ASSIGNMENTS, $tenantId, $establishmentId, $assignmentId) ?? [];
            $a = $assignments[$assignmentId];
            $out[] = new TaughtSessionInfo(
                (string) $log['id'],
                $assignmentId,
                (string) $log['person_id'],
                (string) $log['school_id'],
                (string) $log['department_id'],
                (string) $log['program_id'],
                (string) $log['level_id'],
                (string) $log['group_id'],
                (string) $log['element_id'],
                (string) ($a['element_code'] ?? ''),
                (string) ($a['element_title'] ?? ''),
                (string) $log['session_kind'],
                (string) $log['held_on'],
                (int) $log['minutes'],
                (string) $log['status'],
                self::nullable($log['teacher_signed_at'] ?? null),
                self::nullable($log['content'] ?? null),
                self::nullable($log['slot_id'] ?? null),
                self::nullable($log['vacation_hour_id'] ?? null),
                self::nullable($a['staff_id'] ?? null),
                self::nullable($a['staff_assignment_id'] ?? null),
            );
        }

        return $out;
    }

    public function teachersWithValidatedHours(string $tenantId, string $establishmentId, string $month): array
    {
        [$from, $to] = self::bounds($month);
        $seen = [];
        foreach ($this->store->select(self::LOGS, $tenantId, $establishmentId, ['status' => 'validated'], 'person_id, department_id', 10000, 'held_on >= ? AND held_on < ?', [$from, $to]) as $log) {
            $key = $log['person_id'] . '|' . $log['department_id'];
            $seen[$key] ??= ['person_id' => (string) $log['person_id'], 'school_id' => (string) $log['school_id'], 'department_id' => (string) $log['department_id']];
        }

        return array_values($seen);
    }

    /** @return array{0: string, 1: string} premier jour du mois et premier jour du mois suivant */
    private static function bounds(string $month): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m', $month);
        if ($start === false) {
            throw new \InvalidArgumentException('Mois AAAA-MM attendu : ' . $month);
        }

        return [$start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')];
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
