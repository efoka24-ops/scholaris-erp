<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use Scholaris\Core\Http\ListQuery;

/** Catalogue de frais (fee_items) et grilles (fee_schedules), cloisonnés par client et établissement. */
final class CatalogRepository
{
    public const ITEMS = 'fee_items';
    public const SCHEDULES = 'fee_schedules';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string, string> $filters colonne => valeur (colonnes déjà validées par le contrôleur)
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function page(string $table, string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            $where .= ' AND ' . $column . ' = ?';
            $params[] = $value;
        }

        return $this->db->page($table, $where, $params, $query);
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one(
            sprintf('SELECT * FROM %s WHERE tenant_id = ? AND establishment_id = ? AND id = ?', $table),
            [$tenantId, $establishmentId, $id],
        );
    }

    /** @return array<string, mixed>|null frais de l'établissement portant ce code stable (S10) */
    public function findByCode(string $tenantId, string $establishmentId, string $code): ?array
    {
        return $this->db->one('SELECT * FROM fee_items WHERE tenant_id = ? AND establishment_id = ? AND code = ?', [$tenantId, $establishmentId, $code]);
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $this->db->insert($table, $row);
    }

    /** @param array<string, scalar|null> $set */
    public function update(string $table, string $tenantId, string $establishmentId, string $id, array $set): void
    {
        $this->db->update($table, $set, ['tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'id' => $id]);
    }

    /**
     * Lignes de grille applicables à une inscription : la plus spécifique (classe > niveau/filière > générale) par frais.
     *
     * @return list<array<string, mixed>>
     */
    public function applicableSchedules(string $tenantId, string $establishmentId, string $academicYearId, ?string $structureId, ?string $level, ?string $track): array
    {
        $rows = $this->db->all(
            'SELECT s.*, f.kind AS item_kind, f.name AS item_name, f.code AS item_code FROM fee_schedules s
             JOIN fee_items f ON f.id = s.fee_item_id AND f.tenant_id = s.tenant_id AND f.active = 1
             WHERE s.tenant_id = ? AND s.establishment_id = ? AND s.academic_year_id = ? ORDER BY s.id',
            [$tenantId, $establishmentId, $academicYearId],
        );

        $best = [];
        foreach ($rows as $row) {
            $score = 0;
            foreach ([['structure_id', $structureId, 4], ['level', $level, 2], ['track', $track, 1]] as [$column, $wanted, $weight]) {
                if ($row[$column] === null) {
                    continue;
                }
                if ($row[$column] !== $wanted) {
                    continue 2;
                }
                $score += $weight;
            }
            $key = (string) $row['fee_item_id'];
            if (!isset($best[$key]) || $score > $best[$key][0]) {
                $best[$key] = [$score, $row];
            }
        }

        return array_values(array_map(static fn (array $pair): array => $pair[1], $best));
    }
}
