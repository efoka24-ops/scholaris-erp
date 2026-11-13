<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;

/** Accès au registre d’école, systématiquement borné au client. */
final class PdoSchoolRegistry implements SchoolRegistry
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function find(string $tenantId, string $establishmentId): ?SchoolInfo
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT tenant_id, establishment_id, official_code, subsystem, cycle, school_group_ref, status FROM pub_schools WHERE tenant_id = ? AND establishment_id = ?',
        );
        $query->execute([$tenantId, $establishmentId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return self::info($row);
        }

        // Compatibilité des environnements de test et des déploiements pendant la reprise des données historiques.
        $legacy = $this->connections->pdo()->prepare(
            'SELECT tenant_id, establishment_id, school_code FROM pp_school_profiles WHERE tenant_id = ? AND establishment_id = ?',
        );
        $legacy->execute([$tenantId, $establishmentId]);
        $row = $legacy->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::legacyInfo($row) : null;
    }

    public function groupOf(string $tenantId, string $establishmentId): array
    {
        $current = $this->find($tenantId, $establishmentId);
        if ($current === null) {
            return [];
        }
        if ($current->schoolGroupRef === null) {
            return [$current];
        }

        $query = $this->connections->pdo()->prepare(
            'SELECT tenant_id, establishment_id, official_code, subsystem, cycle, school_group_ref, status FROM pub_schools WHERE tenant_id = ? AND school_group_ref = ? ORDER BY official_code',
        );
        $query->execute([$tenantId, $current->schoolGroupRef]);

        return array_values(array_map(self::info(...), $query->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    public function isOpen(string $tenantId, string $establishmentId): bool
    {
        return $this->find($tenantId, $establishmentId)?->status === SchoolInfo::STATUS_OPEN;
    }

    /** @param array<string, mixed> $row */
    private static function info(array $row): SchoolInfo
    {
        return new SchoolInfo(
            (string) $row['tenant_id'],
            (string) $row['establishment_id'],
            (string) $row['official_code'],
            (string) $row['subsystem'],
            (string) $row['cycle'],
            $row['school_group_ref'] !== null ? (string) $row['school_group_ref'] : null,
            (string) $row['status'],
        );
    }

    /** @param array<string, mixed> $row */
    private static function legacyInfo(array $row): SchoolInfo
    {
        return new SchoolInfo(
            (string) $row['tenant_id'],
            (string) $row['establishment_id'],
            (string) $row['school_code'],
            'francophone',
            SchoolInfo::CYCLE_PRIMARY,
            null,
            SchoolInfo::STATUS_OPEN,
        );
    }
}
