<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Infrastructure;

use PDOException;
use Scholaris\Core\Http\ListQuery;

/** Séances d'appel, lignes par élève et statistiques d'assiduité. Toute requête porte tenant_id et establishment_id. */
final class AttendanceRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function session(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM attendance_sessions WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function sessionByClient(string $tenantId, string $establishmentId, string $clientUuid): ?array
    {
        return $this->db->one('SELECT * FROM attendance_sessions WHERE tenant_id = ? AND establishment_id = ? AND client_uuid = ?', [$tenantId, $establishmentId, $clientUuid]);
    }

    /** @return array<string, mixed>|null */
    public function sessionBySlotDay(string $tenantId, string $slotId, string $date): ?array
    {
        return $this->db->one('SELECT * FROM attendance_sessions WHERE tenant_id = ? AND slot_id = ? AND session_date = ?', [$tenantId, $slotId, $date]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function sessionPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach (['status' => 'status', 'slot_id' => 'slot_id', 'structure_id' => 'structure_id', 'date' => 'session_date', 'teacher_person_id' => 'teacher_person_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $filters[$filter];
            }
        }

        return $this->db->page('attendance_sessions', $where, $params, $query);
    }

    /** @return list<array<string, mixed>> */
    public function records(string $tenantId, string $sessionId): array
    {
        return $this->db->all('SELECT * FROM attendance_records WHERE tenant_id = ? AND session_id = ? ORDER BY person_id', [$tenantId, $sessionId]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function recordPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach (['session_id' => 'session_id', 'person_id' => 'person_id', 'status' => 'status', 'date' => 'session_date'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $filters[$filter];
            }
        }
        if (isset($filters['justified'])) {
            $where .= ' AND justified = ?';
            $params[] = $filters['justified'] === '1' || $filters['justified'] === 'true' ? 1 : 0;
        }

        return $this->db->page('attendance_records', $where, $params, $query);
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $this->db->insert($table, $row);
    }

    /** @param array<string, scalar|null> $set */
    public function updateSession(string $tenantId, string $id, array $set): void
    {
        $this->db->update('attendance_sessions', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /** @param array<string, scalar|null> $set */
    public function updateRecord(string $tenantId, string $id, array $set): void
    {
        $this->db->update('attendance_records', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /** Réserve la notification de l'élève pour ce jour : faux si une notification existe déjà (une seule par élève et par jour). */
    public function claimNotification(string $tenantId, string $establishmentId, string $personId, string $date, string $sessionId, string $id, string $now): bool
    {
        try {
            $this->db->insert('absence_notifications', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'person_id' => $personId,
                'absence_date' => $date, 'session_id' => $sessionId, 'status' => 'pending', 'recipients' => 0, 'created_at' => $now,
            ]);
        } catch (PDOException $e) {
            if ($this->db->isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * Notifications d'absence en échec, les plus anciennes d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function failedNotifications(?string $tenantId, int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        if ($tenantId === null) {
            return $this->db->all(sprintf('SELECT * FROM absence_notifications WHERE status = ? ORDER BY created_at, id LIMIT %d', $limit), ['failed']);
        }

        return $this->db->all(sprintf('SELECT * FROM absence_notifications WHERE tenant_id = ? AND status = ? ORDER BY created_at, id LIMIT %d', $limit), [$tenantId, 'failed']);
    }

    /** Réserve une notification en échec pour un rejeu : faux si un autre rejeu l'a déjà prise. */
    public function reopenNotification(string $tenantId, string $id): bool
    {
        return $this->db->update('absence_notifications', ['status' => 'pending'], ['tenant_id' => $tenantId, 'id' => $id, 'status' => 'failed']) === 1;
    }

    public function finishNotification(string $tenantId, string $id, string $status, int $recipients): void
    {
        $this->db->update('absence_notifications', ['status' => $status, 'recipients' => $recipients], ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /**
     * Cumuls des séances validées, groupés par apprenant ou par matière.
     *
     * @return list<array{key: string, sessions: int, absences: int, unjustified: int, lates: int}>
     */
    public function aggregate(string $tenantId, string $establishmentId, ?string $from, ?string $to, ?string $personId, string $groupBy): array
    {
        $column = $groupBy === 'subject' ? 's.subject_label' : 'r.person_id';
        $where = "r.tenant_id = ? AND r.establishment_id = ? AND s.status = 'validated'";
        $params = [$tenantId, $establishmentId];
        if ($personId !== null) {
            $where .= ' AND r.person_id = ?';
            $params[] = $personId;
        }
        if ($from !== null) {
            $where .= ' AND r.session_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where .= ' AND r.session_date <= ?';
            $params[] = $to;
        }
        $rows = $this->db->all(
            "SELECT $column AS group_key, COUNT(*) AS sessions,
                SUM(CASE WHEN r.status = 'A' THEN 1 ELSE 0 END) AS absences,
                SUM(CASE WHEN r.status = 'A' AND r.justified = 0 THEN 1 ELSE 0 END) AS unjustified,
                SUM(CASE WHEN r.status = 'R' THEN 1 ELSE 0 END) AS lates
             FROM attendance_records r JOIN attendance_sessions s ON s.id = r.session_id AND s.tenant_id = r.tenant_id
             WHERE $where GROUP BY $column ORDER BY $column",
            $params,
        );

        return array_map(static fn (array $row): array => [
            'key' => (string) $row['group_key'],
            'sessions' => (int) $row['sessions'],
            'absences' => (int) $row['absences'],
            'unjustified' => (int) $row['unjustified'],
            'lates' => (int) $row['lates'],
        ], $rows);
    }

    /** @return list<string> apprenants absents (statut A) ce jour-là dans des séances validées */
    public function absentOn(string $tenantId, string $establishmentId, string $date): array
    {
        $rows = $this->db->all(
            "SELECT DISTINCT r.person_id FROM attendance_records r JOIN attendance_sessions s ON s.id = r.session_id AND s.tenant_id = r.tenant_id
             WHERE r.tenant_id = ? AND r.establishment_id = ? AND r.session_date = ? AND r.status = 'A' AND s.status = 'validated' ORDER BY r.person_id",
            [$tenantId, $establishmentId, $date],
        );

        return array_map(static fn (array $row): string => (string) $row['person_id'], $rows);
    }

    /**
     * Cumuls par apprenant et par élément constitutif (séances validées rattachées à un EC).
     *
     * @return list<array{person: string, unit: string, sessions: int, absences: int, unjustified: int, lates: int, structures: list<string>}>
     */
    public function unitAggregate(string $tenantId, string $establishmentId, ?string $from, ?string $to, ?string $personId, ?string $courseUnitId): array
    {
        $where = "r.tenant_id = ? AND r.establishment_id = ? AND s.status = 'validated' AND s.course_unit_id IS NOT NULL";
        $params = [$tenantId, $establishmentId];
        foreach (['r.person_id' => $personId, 's.course_unit_id' => $courseUnitId] as $column => $value) {
            if ($value !== null) {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $value;
            }
        }
        if ($from !== null) {
            $where .= ' AND r.session_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where .= ' AND r.session_date <= ?';
            $params[] = $to;
        }
        $rows = $this->db->all(
            "SELECT r.person_id AS person_key, s.course_unit_id AS unit_key, s.structure_id AS structure_key, COUNT(*) AS sessions,
                SUM(CASE WHEN r.status = 'A' THEN 1 ELSE 0 END) AS absences,
                SUM(CASE WHEN r.status = 'A' AND r.justified = 0 THEN 1 ELSE 0 END) AS unjustified,
                SUM(CASE WHEN r.status = 'R' THEN 1 ELSE 0 END) AS lates
             FROM attendance_records r JOIN attendance_sessions s ON s.id = r.session_id AND s.tenant_id = r.tenant_id
             WHERE $where GROUP BY r.person_id, s.course_unit_id, s.structure_id ORDER BY r.person_id, s.course_unit_id, s.structure_id",
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $key = $row['person_key'] . '|' . $row['unit_key'];
            $out[$key] ??= ['person' => (string) $row['person_key'], 'unit' => (string) $row['unit_key'], 'sessions' => 0, 'absences' => 0, 'unjustified' => 0, 'lates' => 0, 'structures' => []];
            $out[$key]['sessions'] += (int) $row['sessions'];
            $out[$key]['absences'] += (int) $row['absences'];
            $out[$key]['unjustified'] += (int) $row['unjustified'];
            $out[$key]['lates'] += (int) $row['lates'];
            $out[$key]['structures'][] = (string) $row['structure_key'];
        }

        return array_values($out);
    }
}
