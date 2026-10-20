<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Registry;

use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Tests\Support\Organisation\OrganisationTestCase;

final class TerritoryRegistryTest extends OrganisationTestCase
{
    public function testTerritoryHierarchyIsTenantScopedAndReturnedFromLeafToRoot(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('tenant-syn-a', 'est-syn-a', 'École SYN-A', 'primary', 'manager');
        $this->grantAll('manager', 'organisation.read', 'organisation.manage');

        $region = $this->createTerritory('region', 'SYN-R-01', 'Région SYN', null);
        $department = $this->createTerritory('departement', 'SYN-D-01', 'Département SYN', $region);
        $district = $this->createTerritory('arrondissement', 'SYN-A-01', 'Arrondissement SYN', $department);

        [$versionResponse] = $this->api('POST', '/territories/' . $region . '/versions', 'manager', 'tenant-syn-a', 'est-syn-a', [
            'type' => 'region',
            'code' => 'SYN-R-01-V2',
            'label_fr' => 'Région SYN révisée',
            'label_en' => 'Revised SYN Region',
            'parent_id' => null,
            'valid_from' => '2026-06-01T00:00:00+00:00',
        ]);
        self::assertSame(201, $versionResponse->getStatusCode());

        $directory = $this->kernel->container()->get(TerritoryDirectory::class);
        $lineage = $directory->lineage('tenant-syn-a', $district, '2026-10-14T12:00:00+00:00');
        $pastRegion = $directory->find('tenant-syn-a', $region, '2026-05-01T12:00:00+00:00');

        self::assertSame([$district, $department, $region], array_map(static fn ($item): string => $item->id, $lineage));
        self::assertSame('SYN-R-01-V2', $lineage[2]->code);
        self::assertSame('SYN-R-01', $pastRegion?->code);
        [$lineageResponse, $lineageJson] = $this->api('GET', '/territories/' . $district . '/lineage?at=2026-10-14T12%3A00%3A00%2B00%3A00', 'manager', 'tenant-syn-a', 'est-syn-a');
        self::assertSame(200, $lineageResponse->getStatusCode());
        self::assertSame([$district, $department, $region], array_column($lineageJson['data'], 'id'));
        [$descendantsResponse, $descendantsJson] = $this->api('GET', '/territories/' . $region . '/descendants', 'manager', 'tenant-syn-a', 'est-syn-a');
        self::assertSame(200, $descendantsResponse->getStatusCode());
        self::assertEqualsCanonicalizing([$region, $department, $district], array_column($descendantsJson['data'], 'id'));
        self::assertSame([], $directory->lineage('tenant-syn-b', $district, '2026-10-14T12:00:00+00:00'));
        self::assertNull($directory->find('tenant-syn-b', $district, '2026-10-14T12:00:00+00:00'));
    }

    public function testTerritoryEndpointsRequireExplicitOrganisationPermissionAndDoNotExposeOtherTenantNodes(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('tenant-syn-a', 'est-syn-a', 'École SYN-A', 'primary', 'manager', 'reader-without-right');
        $this->seedEstablishment('tenant-syn-b', 'est-syn-b', 'École SYN-B', 'primary', 'manager');
        $this->grantAll('manager', 'organisation.read', 'organisation.manage');
        $id = $this->createTerritory('region', 'SYN-R-02', 'Région SYN', null);

        [$forbidden] = $this->api('GET', '/territories/' . $id . '/lineage', 'reader-without-right', 'tenant-syn-a', 'est-syn-a');
        self::assertSame(403, $forbidden->getStatusCode());

        [$notFound] = $this->api('GET', '/territories/' . $id . '/lineage', 'manager', 'tenant-syn-b', 'est-syn-b');
        self::assertSame(404, $notFound->getStatusCode());
    }

    public function testSchoolCannotBeAssignedATerritoryFromAnotherTenant(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('tenant-syn-a', 'est-syn-a', 'École SYN-A', 'primary', 'manager');
        $this->seedEstablishment('tenant-syn-b', 'est-syn-b', 'École SYN-B', 'primary', 'manager');
        $this->grantAll('manager', 'organisation.read', 'organisation.manage', 'public_primary.registry.manage');
        $territoryId = $this->createTerritory('region', 'SYN-R-CROSS', 'Région SYN', null);

        [$response, $problem] = $this->api('PUT', '/public-primary/registry/profile', 'manager', 'tenant-syn-b', 'est-syn-b', [
            'name' => 'École SYN-B',
            'school_code' => 'SYN-SCHOOL-B',
            'territory_id' => $territoryId,
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(422, $problem['status']);
    }

    private function createTerritory(string $type, string $code, string $label, ?string $parentId): string
    {
        [$response, $json] = $this->api('POST', '/territories', 'manager', 'tenant-syn-a', 'est-syn-a', [
            'type' => $type,
            'code' => $code,
            'label_fr' => $label,
            'label_en' => $label . ' EN',
            'parent_id' => $parentId,
            'valid_from' => '2026-01-01T00:00:00+00:00',
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $json['data']['id']);

        return (string) $json['data']['id'];
    }
}
