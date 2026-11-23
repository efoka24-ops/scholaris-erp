<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryInfo;

/** Contrat temporel du découpage : fixtures SYN uniquement, sans schéma ni code officiel. */
final class TerritoryHistoryTest extends TestCase
{
    public function testLaLigneeRetourneeDependDeLaDateDeReference(): void
    {
        $directory = new SyntheticTerritoryDirectory();
        $directory->activateNewSchoolAreaParent('2026-09-01T00:00:00+01:00');

        $closedLineage = $directory->lineage('tenant-syn-1', '01J9Z6Q0A1B2C3D4E5F6G7H8J1', '2025-08-31T23:59:59+01:00');
        $currentLineage = $directory->lineage('tenant-syn-1', '01J9Z6Q0A1B2C3D4E5F6G7H8J1', '2026-09-01T00:00:00+01:00');

        self::assertSame(['01J9Z6Q0A1B2C3D4E5F6G7H8J1', '01J9Z6Q0A1B2C3D4E5F6G7H8J2', '01J9Z6Q0A1B2C3D4E5F6G7H8J3'], array_map(static fn (TerritoryInfo $item): string => $item->id, $closedLineage));
        self::assertSame(['01J9Z6Q0A1B2C3D4E5F6G7H8J1', '01J9Z6Q0A1B2C3D4E5F6G7H8J4', '01J9Z6Q0A1B2C3D4E5F6G7H8J3'], array_map(static fn (TerritoryInfo $item): string => $item->id, $currentLineage));
        self::assertCount(3, $directory->descendantsOf('tenant-syn-1', '01J9Z6Q0A1B2C3D4E5F6G7H8J3', '2026-09-01T00:00:00+01:00'));
        self::assertSame([], $directory->lineage('tenant-syn-2', '01J9Z6Q0A1B2C3D4E5F6G7H8J1', '2026-09-01T00:00:00+01:00'));
        self::assertSame('tenant-syn-1', $closedLineage[1]->tenantId);
        self::assertSame('SYN-DISTRICT-OLD', $closedLineage[1]->code);
    }

    public function testUnSnapshotClotureResteInchangeApresModificationDuDecoupage(): void
    {
        $directory = new SyntheticTerritoryDirectory();
        $closedSnapshot = array_map(
            static fn (TerritoryInfo $item): array => ['id' => $item->id, 'version' => $item->version, 'parent_id' => $item->parentId],
            $directory->lineage('tenant-syn-1', '01J9Z6Q0A1B2C3D4E5F6G7H8J1', '2025-08-31T23:59:59+01:00'),
        );

        // Une nouvelle version de la hiérarchie prend effet ; elle ne réécrit pas le snapshot déjà clôturé.
        $directory->activateNewSchoolAreaParent('2026-09-01T00:00:00+01:00');
        $currentLineage = $directory->lineage('tenant-syn-1', '01J9Z6Q0A1B2C3D4E5F6G7H8J1', '2026-09-01T00:00:00+01:00');

        self::assertSame('01J9Z6Q0A1B2C3D4E5F6G7H8J4', $currentLineage[1]->id);
        self::assertSame('01J9Z6Q0A1B2C3D4E5F6G7H8J2', $closedSnapshot[1]['id']);
        self::assertSame('v1', $closedSnapshot[1]['version']);
    }
}

/** Faux annuaire à versions effectives, utilisé pour exprimer le contrat avant l'adaptateur T022. */
final class SyntheticTerritoryDirectory implements TerritoryDirectory
{
    private bool $newParentActive = false;

    public function find(string $tenantId, string $territoryId, string $at): ?TerritoryInfo
    {
        foreach ($this->versionAt($at) as $territory) {
            if ($territory->tenantId === $tenantId && $territory->id === $territoryId) {
                return $territory;
            }
        }

        return null;
    }

    public function lineage(string $tenantId, string $territoryId, string $at): array
    {
        $lineage = [];
        $seen = [];
        $currentId = $territoryId;

        while ($currentId !== '') {
            if (isset($seen[$currentId])) {
                return [];
            }
            $seen[$currentId] = true;
            $territory = $this->find($tenantId, $currentId, $at);
            if ($territory === null) {
                return [];
            }
            $lineage[] = $territory;
            $currentId = $territory->parentId ?? '';
        }

        return $lineage;
    }

    public function descendantsOf(string $tenantId, string $territoryId, string $at): array
    {
        $descendants = [];
        foreach ($this->versionAt($at) as $territory) {
            if ($territory->tenantId !== $tenantId) {
                continue;
            }
            foreach ($this->lineage($tenantId, $territory->id, $at) as $ancestor) {
                if ($ancestor->id === $territoryId) {
                    $descendants[] = $territory;
                    break;
                }
            }
        }

        return $descendants;
    }

    public function territoryOf(string $tenantId, string $establishmentId, string $at): ?TerritoryInfo
    {
        return null;
    }

    public function establishmentsIn(string $tenantId, string $territoryId, string $at): array
    {
        return [];
    }

    public function activateNewSchoolAreaParent(string $effectiveAt): void
    {
        $this->newParentActive = $effectiveAt >= '2026-09-01T00:00:00+01:00';
    }

    /** @return list<TerritoryInfo> */
    private function versionAt(string $at): array
    {
        $newParent = $this->newParentActive && $at >= '2026-09-01T00:00:00+01:00';
        $district = $newParent ? '01J9Z6Q0A1B2C3D4E5F6G7H8J4' : '01J9Z6Q0A1B2C3D4E5F6G7H8J2';
        $districtCode = $newParent ? 'SYN-DISTRICT-NEW' : 'SYN-DISTRICT-OLD';
        $districtVersion = $newParent ? 'v2' : 'v1';

        return [
            new TerritoryInfo('tenant-syn-1', '01J9Z6Q0A1B2C3D4E5F6G7H8J1', 'arrondissement', 'SYN-École', 'SYN-School Area', $district, '2020-01-01T00:00:00+01:00', null, $newParent ? 'v2' : 'v1', 'SYN-SCHOOL-AREA'),
            new TerritoryInfo('tenant-syn-1', $district, 'departement', 'SYN-Département', 'SYN-District', '01J9Z6Q0A1B2C3D4E5F6G7H8J3', $newParent ? '2026-09-01T00:00:00+01:00' : '2020-01-01T00:00:00+01:00', null, $districtVersion, $districtCode),
            new TerritoryInfo('tenant-syn-1', '01J9Z6Q0A1B2C3D4E5F6G7H8J3', 'region', 'SYN-Région', 'SYN-Region', null, '2020-01-01T00:00:00+01:00', null, 'v1', 'SYN-REGION'),
        ];
    }
}
