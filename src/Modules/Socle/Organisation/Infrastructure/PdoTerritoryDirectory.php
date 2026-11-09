<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryInfo;
use Scholaris\Modules\Socle\Organisation\Domain\Instant;

/** Lecture des versions de territoire, strictement bornée au tenant et à l'instant demandé. */
final class PdoTerritoryDirectory implements TerritoryDirectory
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function find(string $tenantId, string $territoryId, string $at): ?TerritoryInfo
    {
        $row = $this->activeRow($tenantId, $territoryId, $at);

        return $row === null ? null : self::info($row);
    }

    public function lineage(string $tenantId, string $territoryId, string $at): array
    {
        $lineage = [];
        $visited = [];
        $next = $territoryId;
        while ($next !== null) {
            if (isset($visited[$next]) || count($lineage) >= 100) {
                return [];
            }
            $visited[$next] = true;
            $row = $this->activeRow($tenantId, $next, $at);
            if ($row === null) {
                return [];
            }
            $lineage[] = self::info($row);
            $next = $row['parent_id'] === null ? null : (string) $row['parent_id'];
        }

        return $lineage;
    }

    public function descendantsOf(string $tenantId, string $territoryId, string $at): array
    {
        if ($this->activeRow($tenantId, $territoryId, $at) === null) {
            return [];
        }
        $statement = $this->connections->pdo()->prepare(
            'SELECT v.* FROM organisation_territory_versions v WHERE v.tenant_id = ? ORDER BY v.territory_id, v.version DESC',
        );
        $statement->execute([$tenantId]);
        $active = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!self::activeAt($row, $at)) {
                continue;
            }
            $key = (string) $row['territory_id'];
            if (!isset($active[$key]) || (int) $row['version'] > (int) $active[$key]['version']) {
                $active[$key] = $row;
            }
        }

        $included = [$territoryId => true];
        do {
            $changed = false;
            foreach ($active as $id => $row) {
                $parent = $row['parent_id'] === null ? null : (string) $row['parent_id'];
                if ($parent !== null && isset($included[$parent]) && !isset($included[$id])) {
                    $included[$id] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        $result = [];
        foreach ($active as $id => $row) {
            if (isset($included[$id])) {
                $result[] = self::info($row);
            }
        }

        return $result;
    }

    public function territoryOf(string $tenantId, string $establishmentId, string $at): ?TerritoryInfo
    {
        $instant = $this->instant($at);
        if ($instant === null) {
            return null;
        }
        $statement = $this->connections->pdo()->prepare(
            'SELECT territory_id FROM organisation_establishment_territories '
            . 'WHERE tenant_id = ? AND establishment_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to > ?) '
            . 'ORDER BY valid_from DESC LIMIT 1',
        );
        $statement->execute([$tenantId, $establishmentId, $instant, $instant]);
        $territoryId = $statement->fetchColumn();

        return is_string($territoryId) ? $this->find($tenantId, $territoryId, $at) : null;
    }

    public function establishmentsIn(string $tenantId, string $territoryId, string $at): array
    {
        $instant = $this->instant($at);
        if ($instant === null) {
            return [];
        }
        $ids = array_map(static fn (TerritoryInfo $info): string => $info->id, $this->descendantsOf($tenantId, $territoryId, $at));
        if ($ids === []) {
            return [];
        }
        $statement = $this->connections->pdo()->prepare(
            'SELECT DISTINCT establishment_id FROM organisation_establishment_territories '
            . 'WHERE tenant_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to > ?) '
            . 'AND territory_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY establishment_id',
        );
        $statement->execute([$tenantId, $instant, $instant, ...$ids]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function instant(string $at): ?string
    {
        try {
            return Instant::utc($at);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function activeRow(string $tenantId, string $territoryId, string $at): ?array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM organisation_territory_versions WHERE tenant_id = ? AND territory_id = ? ORDER BY version DESC',
        );
        $statement->execute([$tenantId, $territoryId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (self::activeAt($row, $at)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Version active à l'instant demandé, bornes [valid_from, valid_to[ : début INCLUS (C-115, C110-PP2). Les dates des versions
     * sont gardées telles que saisies (décalage horaire compris) : la comparaison se fait en instants, jamais en texte, sinon un
     * rattachement daté du jour même du début du territoire, normalisé en UTC, paraissait antérieur à ce début.
     *
     * @param array<string, mixed> $row
     */
    private static function activeAt(array $row, string $at): bool
    {
        $instant = self::timestamp($at);
        $from = self::timestamp((string) $row['valid_from']);
        if ($instant === null || $from === null || $from > $instant) {
            return false;
        }
        if ($row['valid_to'] === null) {
            return true;
        }
        $to = self::timestamp((string) $row['valid_to']);

        return $to !== null && $to > $instant;
    }

    private static function timestamp(string $value): ?int
    {
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $row */
    private static function info(array $row): TerritoryInfo
    {
        return new TerritoryInfo(
            (string) $row['tenant_id'],
            (string) $row['territory_id'],
            (string) $row['territory_type'],
            (string) $row['label_fr'],
            (string) $row['label_en'],
            $row['parent_id'] === null ? null : (string) $row['parent_id'],
            (string) $row['valid_from'],
            $row['valid_to'] === null ? null : (string) $row['valid_to'],
            (string) $row['version'],
            (string) $row['code'],
        );
    }
}
