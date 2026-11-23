<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Core\Http\ListQuery;

/** Référentiel (matières, éléments) et épreuves. Chaque requête porte tenant_id ; l'établissement cloisonne les épreuves. */
final class CatalogRepository
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
    public function subject(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM subjects WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function subjectByCode(string $tenantId, string $establishmentId, string $code): ?array
    {
        return $this->db->one('SELECT * FROM subjects WHERE tenant_id = ? AND establishment_id = ? AND code = ?', [$tenantId, $establishmentId, $code]);
    }

    /** @param array<string, scalar|null> $set */
    public function updateSubject(string $tenantId, string $id, array $set): void
    {
        $this->db->update('subjects', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function subjectPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        return $this->db->page('subjects', $this->where($filters, ['kind', 'active']), $this->params($tenantId, $establishmentId, $filters, ['kind', 'active']), $query);
    }

    /** @return array<string, mixed>|null matière portant cette clé externe (catalogue de programme) */
    public function subjectByExternalKey(string $tenantId, string $establishmentId, string $externalKey): ?array
    {
        return $this->db->one('SELECT * FROM subjects WHERE tenant_id = ? AND establishment_id = ? AND external_key = ?', [$tenantId, $establishmentId, $externalKey]);
    }

    /** @return array<string, mixed>|null élément portant cette clé externe */
    public function elementByExternalKey(string $tenantId, string $establishmentId, string $externalKey): ?array
    {
        return $this->db->one('SELECT * FROM subject_elements WHERE tenant_id = ? AND establishment_id = ? AND external_key = ?', [$tenantId, $establishmentId, $externalKey]);
    }

    /** @param array<string, scalar|null> $set */
    public function updateElement(string $tenantId, string $id, array $set): void
    {
        $this->db->update('subject_elements', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function elementsOf(string $tenantId, string $subjectId): array
    {
        return $this->db->all('SELECT * FROM subject_elements WHERE tenant_id = ? AND subject_id = ? ORDER BY id', [$tenantId, $subjectId]);
    }

    /** @return array<string, mixed>|null */
    public function element(string $tenantId, string $subjectId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM subject_elements WHERE tenant_id = ? AND subject_id = ? AND id = ?', [$tenantId, $subjectId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function assessment(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM assessments WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @param array<string, scalar|null> $set */
    public function updateAssessment(string $tenantId, string $id, array $set): void
    {
        $this->db->update('assessments', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function assessmentPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $allowed = ['subject_id', 'structure_id', 'period_id', 'status', 'kind'];

        return $this->db->page('assessments', $this->where($filters, $allowed), $this->params($tenantId, $establishmentId, $filters, $allowed), $query);
    }

    /**
     * Épreuves d'une classe pour une période (ordre de création), éventuellement d'une seule matière ou d'une seule session.
     *
     * @return list<array<string, mixed>>
     */
    public function forSheet(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $subjectId = null, ?string $session = null): array
    {
        $sql = 'SELECT * FROM assessments WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND period_id = ?';
        $params = [$tenantId, $establishmentId, $structureId, $periodId];
        if ($subjectId !== null) {
            $sql .= ' AND subject_id = ?';
            $params[] = $subjectId;
        }
        if ($session !== null) {
            $sql .= ' AND exam_session = ?';
            $params[] = $session;
        }

        return $this->db->all($sql . ' ORDER BY id', $params);
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>> matières par identifiant
     */
    public function subjectsByIds(string $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT * FROM subjects WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            [$tenantId, ...$ids],
        );

        return array_column($rows, null, 'id');
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>> éléments par identifiant
     */
    public function elementsByIds(string $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT * FROM subject_elements WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            [$tenantId, ...$ids],
        );

        return array_column($rows, null, 'id');
    }

    /**
     * @param array<string, string> $filters
     * @param list<string> $allowed
     */
    private function where(array $filters, array $allowed): string
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        foreach (array_keys($filters) as $column) {
            if (in_array($column, $allowed, true)) {
                $where .= ' AND ' . $column . ' = ?';
            }
        }

        return $where;
    }

    /**
     * @param array<string, string> $filters
     * @param list<string> $allowed
     * @return list<string>
     */
    private function params(string $tenantId, string $establishmentId, array $filters, array $allowed): array
    {
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            if (in_array($column, $allowed, true)) {
                $params[] = $value;
            }
        }

        return $params;
    }
}
