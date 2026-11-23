<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Core\Http\ListQuery;

/** Notes, historique append-only (grade_events) et demandes de correction. */
final class GradeRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $this->db->insert($table, $row);
    }

    /** @return array<string, mixed>|null */
    public function grade(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM grades WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function cell(string $tenantId, string $assessmentId, string $personId): ?array
    {
        return $this->db->one('SELECT * FROM grades WHERE tenant_id = ? AND assessment_id = ? AND person_id = ?', [$tenantId, $assessmentId, $personId]);
    }

    /** @return array<string, mixed>|null */
    public function byClientUuid(string $tenantId, string $clientUuid): ?array
    {
        return $this->db->one('SELECT * FROM grades WHERE tenant_id = ? AND client_uuid = ?', [$tenantId, $clientUuid]);
    }

    /** @param array<string, scalar|null> $set */
    public function updateGrade(string $tenantId, string $id, array $set): void
    {
        $this->db->update('grades', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function ofAssessment(string $tenantId, string $assessmentId): array
    {
        return $this->db->all('SELECT * FROM grades WHERE tenant_id = ? AND assessment_id = ? ORDER BY id', [$tenantId, $assessmentId]);
    }

    /**
     * @param list<string> $assessmentIds
     * @return list<array<string, mixed>>
     */
    public function ofAssessments(string $tenantId, array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return [];
        }

        return $this->db->all(
            'SELECT * FROM grades WHERE tenant_id = ? AND assessment_id IN (' . implode(',', array_fill(0, count($assessmentIds), '?')) . ') ORDER BY id',
            [$tenantId, ...$assessmentIds],
        );
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function gradePage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            if (in_array($column, ['assessment_id', 'person_id'], true)) {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $value;
            }
        }

        return $this->db->page('grades', $where, $params, $query);
    }

    /** @return list<array<string, mixed>> historique d'une note, du plus ancien au plus récent */
    public function events(string $tenantId, string $gradeId): array
    {
        return $this->db->all('SELECT * FROM grade_events WHERE tenant_id = ? AND grade_id = ? ORDER BY happened_at, id', [$tenantId, $gradeId]);
    }

    /** @return list<string> utilisateurs qui ont saisi au moins une note de l'épreuve (hors corrections approuvées) */
    public function authorsOf(string $tenantId, string $assessmentId): array
    {
        $rows = $this->db->all(
            "SELECT DISTINCT author FROM grade_events WHERE tenant_id = ? AND assessment_id = ? AND event_kind <> 'correction'",
            [$tenantId, $assessmentId],
        );

        return array_map(static fn (array $row): string => (string) $row['author'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function correction(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM grade_corrections WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function pendingCorrectionOf(string $tenantId, string $gradeId): ?array
    {
        return $this->db->one("SELECT * FROM grade_corrections WHERE tenant_id = ? AND grade_id = ? AND status = 'pending'", [$tenantId, $gradeId]);
    }

    /** @param array<string, scalar|null> $set */
    public function updateCorrection(string $tenantId, string $id, array $set): void
    {
        $this->db->update('grade_corrections', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function correctionPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            if (in_array($column, ['status', 'assessment_id', 'person_id'], true)) {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $value;
            }
        }

        return $this->db->page('grade_corrections', $where, $params, $query);
    }
}
