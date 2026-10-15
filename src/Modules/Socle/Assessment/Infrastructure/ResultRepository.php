<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Core\Http\ListQuery;

/** Règles de calcul, résultats, conseils de classe, délibérations et file des bulletins. */
final class ResultRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $this->db->insert($table, $row);
    }

    /** @param array<string, scalar|null> $set */
    public function update(string $table, string $tenantId, string $id, array $set): void
    {
        $this->db->update($table, $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    // --- Règles de calcul versionnées par année ---------------------------------------------------------------

    /** @return array<string, mixed>|null dernière version de l'année pour cette structure (null = version sans structure) */
    public function latestRules(string $tenantId, string $establishmentId, string $yearId, ?string $structureId = null): ?array
    {
        if ($structureId === null) {
            return $this->db->one(
                'SELECT * FROM calculation_rules WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ? AND structure_id IS NULL ORDER BY version DESC LIMIT 1',
                [$tenantId, $establishmentId, $yearId],
            );
        }

        return $this->db->one(
            'SELECT * FROM calculation_rules WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ? AND structure_id = ? ORDER BY version DESC LIMIT 1',
            [$tenantId, $establishmentId, $yearId, $structureId],
        );
    }

    /** Plus haut numéro de version de l'année, toutes structures confondues (0 si aucune). */
    public function latestVersion(string $tenantId, string $establishmentId, string $yearId): int
    {
        return $this->db->int(
            'SELECT MAX(version) FROM calculation_rules WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ?',
            [$tenantId, $establishmentId, $yearId],
        );
    }

    /** @return list<array<string, mixed>> toutes les versions de l'année */
    public function rulesOfYear(string $tenantId, string $establishmentId, string $yearId): array
    {
        return $this->db->all(
            'SELECT * FROM calculation_rules WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ? ORDER BY version',
            [$tenantId, $establishmentId, $yearId],
        );
    }

    /** @return array<string, mixed>|null */
    public function rules(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM calculation_rules WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function rulesPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach (['academic_year_id', 'structure_id'] as $column) {
            if (isset($filters[$column])) {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $filters[$column];
            }
        }

        return $this->db->page('calculation_rules', $where, $params, $query);
    }

    // --- Résultats --------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function result(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM results WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function resultOf(string $tenantId, string $establishmentId, string $personId, string $periodId, string $session = 'normal'): ?array
    {
        return $this->db->one(
            'SELECT * FROM results WHERE tenant_id = ? AND establishment_id = ? AND person_id = ? AND period_id = ? AND exam_session = ?',
            [$tenantId, $establishmentId, $personId, $periodId, $session],
        );
    }

    /** @return list<array<string, mixed>> */
    public function resultsOf(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session = 'normal'): array
    {
        return $this->db->all(
            'SELECT * FROM results WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND period_id = ? AND exam_session = ? ORDER BY id',
            [$tenantId, $establishmentId, $structureId, $periodId, $session],
        );
    }

    /**
     * @param array<string, string> $filters
     * @param list<string>|null $restrictToPersons null = tous ; sinon uniquement ces personnes et seulement les résultats publiés
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function resultPage(string $tenantId, string $establishmentId, array $filters, ?array $restrictToPersons, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            if (in_array($column, ['structure_id', 'period_id', 'person_id', 'status', 'exam_session'], true)) {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $value;
            }
        }
        if ($restrictToPersons !== null) {
            if ($restrictToPersons === []) {
                return [[], null];
            }
            $where .= " AND status = 'published' AND person_id IN (" . implode(',', array_fill(0, count($restrictToPersons), '?')) . ')';
            array_push($params, ...$restrictToPersons);
        }

        return $this->db->page('results', $where, $params, $query);
    }

    /** @return list<array<string, mixed>> résultats publiés, du plus récent au plus ancien */
    public function publishedFor(string $tenantId, string $personId): array
    {
        return $this->db->all(
            "SELECT * FROM results WHERE tenant_id = ? AND person_id = ? AND status = 'published' ORDER BY published_at DESC, id DESC",
            [$tenantId, $personId],
        );
    }

    /** @return array<string, float> moyenne du résultat publié le plus récent par personne de l'établissement (une requête) */
    public function latestAveragesIn(string $tenantId, string $establishmentId): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT person_id, average FROM results WHERE tenant_id = ? AND establishment_id = ? AND status = 'published' AND average IS NOT NULL
             ORDER BY person_id, published_at DESC, id DESC",
            [$tenantId, $establishmentId],
        ) as $row) {
            $out[(string) $row['person_id']] ??= (float) $row['average'];
        }

        return $out;
    }

    // --- Conseils de classe -----------------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function council(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM class_councils WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function councilOf(string $tenantId, string $establishmentId, string $structureId, string $periodId): ?array
    {
        return $this->db->one(
            'SELECT * FROM class_councils WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND period_id = ?',
            [$tenantId, $establishmentId, $structureId, $periodId],
        );
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function councilPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        return $this->scopedPage('class_councils', $tenantId, $establishmentId, $filters, ['structure_id', 'period_id', 'status'], $query);
    }

    /** @return list<array<string, mixed>> */
    public function councilLines(string $tenantId, string $councilId): array
    {
        return $this->db->all('SELECT * FROM council_lines WHERE tenant_id = ? AND council_id = ? ORDER BY COALESCE(rank_in_class, 100000), id', [$tenantId, $councilId]);
    }

    /** @return array<string, mixed>|null */
    public function councilLine(string $tenantId, string $councilId, string $lineId): ?array
    {
        return $this->db->one('SELECT * FROM council_lines WHERE tenant_id = ? AND council_id = ? AND id = ?', [$tenantId, $councilId, $lineId]);
    }

    // --- Délibérations ----------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function deliberation(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM deliberations WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function deliberationOf(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session = 'normal'): ?array
    {
        return $this->db->one(
            'SELECT * FROM deliberations WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND period_id = ? AND exam_session = ?',
            [$tenantId, $establishmentId, $structureId, $periodId, $session],
        );
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function deliberationPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        return $this->scopedPage('deliberations', $tenantId, $establishmentId, $filters, ['structure_id', 'period_id', 'status', 'exam_session'], $query);
    }

    /** @return list<array<string, mixed>> */
    public function deliberationLines(string $tenantId, string $deliberationId): array
    {
        return $this->db->all('SELECT * FROM deliberation_lines WHERE tenant_id = ? AND deliberation_id = ? ORDER BY id', [$tenantId, $deliberationId]);
    }

    /** @return array<string, mixed>|null */
    public function deliberationLine(string $tenantId, string $deliberationId, string $lineId): ?array
    {
        return $this->db->one('SELECT * FROM deliberation_lines WHERE tenant_id = ? AND deliberation_id = ? AND id = ?', [$tenantId, $deliberationId, $lineId]);
    }

    // --- Bulletins --------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function reportCards(string $tenantId, string $councilId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM report_card_requests WHERE tenant_id = ? AND council_id = ?';
        $params = [$tenantId, $councilId];
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        return $this->db->all($sql . ' ORDER BY id', $params);
    }

    /**
     * @param array<string, string> $filters
     * @param list<string> $allowed
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function scopedPage(string $table, string $tenantId, string $establishmentId, array $filters, array $allowed, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            if (in_array($column, $allowed, true)) {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $value;
            }
        }

        return $this->db->page($table, $where, $params, $query);
    }
}
