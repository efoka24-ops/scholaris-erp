<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Infrastructure;

use InvalidArgumentException;
use Scholaris\Core\Http\ListQuery;

/** Incidents, sanctions et personnes autorisées à récupérer un enfant. */
final class DisciplineRepository
{
    public const INCIDENTS = 'incidents';
    public const SANCTIONS = 'sanctions';
    public const PICKUPS = 'authorized_pickups';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one(sprintf('SELECT * FROM %s WHERE tenant_id = ? AND establishment_id = ? AND id = ?', $this->table($table)), [$tenantId, $establishmentId, $id]);
    }

    /**
     * @param array<string, string> $filters
     * @param array<string, string> $allowed filtre => colonne autorisée
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function page(string $table, string $tenantId, string $establishmentId, array $filters, array $allowed, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($allowed as $filter => $column) {
            if (isset($filters[$filter])) {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $filters[$filter];
            }
        }

        return $this->db->page($this->table($table), $where, $params, $query);
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $this->db->insert($this->table($table), $row);
    }

    /** @param array<string, scalar|null> $set */
    public function update(string $table, string $tenantId, string $id, array $set): void
    {
        $this->db->update($this->table($table), $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> autorisations actives de l'apprenant */
    public function activePickups(string $tenantId, string $establishmentId, string $learnerId): array
    {
        return $this->db->all("SELECT * FROM authorized_pickups WHERE tenant_id = ? AND establishment_id = ? AND learner_id = ? AND status = 'active' ORDER BY id", [$tenantId, $establishmentId, $learnerId]);
    }

    private function table(string $table): string
    {
        return in_array($table, [self::INCIDENTS, self::SANCTIONS, self::PICKUPS], true) ? $table : throw new InvalidArgumentException('Table inconnue.');
    }
}
