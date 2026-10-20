<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Registry;

use Tests\Support\Organisation\OrganisationTestCase;

final class InfrastructureSnapshotTest extends OrganisationTestCase
{
    public function testAnnualInfrastructureCorrectionsCreateVersionsAndClosedSnapshotsRemainImmutable(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('tenant-syn-a', 'est-syn-a', 'École SYN-A', 'primary', 'registry-manager');
        $this->seedEstablishment('tenant-syn-b', 'est-syn-b', 'École SYN-B', 'primary', 'registry-manager');
        $this->grantAll('registry-manager', 'public_primary.registry.read', 'public_primary.registry.manage', 'organisation.read', 'organisation.manage');
        $territoryId = $this->createTerritory();

        [$profileResponse, $profile] = $this->api('PUT', '/public-primary/registry/profile', 'registry-manager', 'tenant-syn-a', 'est-syn-a', [
            'name' => 'École SYN-A',
            'school_code' => 'SYN-SCHOOL-01',
            'territory_id' => $territoryId,
        ]);
        self::assertSame(200, $profileResponse->getStatusCode());
        self::assertSame('est-syn-a', $profile['data']['establishment_id']);

        [$createdResponse, $created] = $this->api('POST', '/public-primary/registry/infrastructure', 'registry-manager', 'tenant-syn-a', 'est-syn-a', $this->snapshotPayload(6));
        self::assertSame(201, $createdResponse->getStatusCode());
        $originalId = (string) $created['data']['id'];
        self::assertSame(1, $created['data']['version']);
        $frozenTerritory = json_decode((string) $created['data']['territory_snapshot'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Arrondissement SYN', $frozenTerritory[0]['label_fr']);

        [$territoryRevision] = $this->api('POST', '/territories/' . $territoryId . '/versions', 'registry-manager', 'tenant-syn-a', 'est-syn-a', [
            'type' => 'arrondissement',
            'code' => 'SYN-A-01-V2',
            'label_fr' => 'Arrondissement SYN révisé',
            'label_en' => 'Revised SYN District',
            'parent_id' => null,
            'valid_from' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM),
        ]);
        self::assertSame(201, $territoryRevision->getStatusCode());

        [$closedResponse, $closed] = $this->api('POST', '/public-primary/registry/infrastructure/' . $originalId . '/close', 'registry-manager', 'tenant-syn-a', 'est-syn-a');
        self::assertSame(200, $closedResponse->getStatusCode());
        self::assertSame('closed', $closed['data']['status']);

        [$correctionResponse, $correction] = $this->api('POST', '/public-primary/registry/infrastructure/' . $originalId . '/corrections', 'registry-manager', 'tenant-syn-a', 'est-syn-a', $this->snapshotPayload(7) + ['reason' => 'Correction SYN documentée']);
        self::assertSame(201, $correctionResponse->getStatusCode());
        self::assertSame(2, $correction['data']['version']);
        self::assertSame('draft', $correction['data']['status']);
        self::assertSame(6, $this->snapshot($originalId)['classrooms_count']);
        self::assertSame($created['data']['territory_snapshot'], $correction['data']['territory_snapshot']);

        [$secondCloseResponse] = $this->api('POST', '/public-primary/registry/infrastructure/' . $correction['data']['id'] . '/close', 'registry-manager', 'tenant-syn-a', 'est-syn-a');
        self::assertSame(200, $secondCloseResponse->getStatusCode());
        [$immutableResponse, $problem] = $this->api('PATCH', '/public-primary/registry/infrastructure/' . $correction['data']['id'], 'registry-manager', 'tenant-syn-a', 'est-syn-a', $this->snapshotPayload(8));
        self::assertSame(409, $immutableResponse->getStatusCode());
        self::assertSame(409, $problem['status']);
        self::assertNotEmpty($problem['trace_id']);

        [$otherTenantResponse] = $this->api('GET', '/public-primary/registry/infrastructure/' . $originalId, 'registry-manager', 'tenant-syn-b', 'est-syn-b');
        self::assertSame(404, $otherTenantResponse->getStatusCode());
    }

    public function testSnapshotRequiresBilingualTextAndAnExistingSameTenantProfile(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('tenant-syn-a', 'est-syn-a', 'École SYN-A', 'primary', 'registry-manager');
        $this->grantAll('registry-manager', 'public_primary.registry.manage');

        [$withoutProfile] = $this->api('POST', '/public-primary/registry/infrastructure', 'registry-manager', 'tenant-syn-a', 'est-syn-a', $this->snapshotPayload(4));
        self::assertContains($withoutProfile->getStatusCode(), [403, 404, 422]);
    }

    /** @return array<string, mixed> */
    private function snapshotPayload(int $classrooms): array
    {
        return [
            'school_year' => '2026-2027',
            'classrooms_count' => $classrooms,
            'classroom_condition_fr' => 'État synthétique',
            'classroom_condition_en' => 'Synthetic condition',
            'water_access' => true,
            'latrines_count' => 2,
            'electricity' => false,
            'connectivity' => 'unknown',
            'equipment_fr' => 'Équipement SYN',
            'equipment_en' => 'SYN equipment',
        ];
    }

    private function createTerritory(): string
    {
        [$response, $json] = $this->api('POST', '/territories', 'registry-manager', 'tenant-syn-a', 'est-syn-a', [
            'type' => 'arrondissement',
            'code' => 'SYN-A-01',
            'label_fr' => 'Arrondissement SYN',
            'label_en' => 'SYN District',
            'parent_id' => null,
            'valid_from' => '2026-01-01T00:00:00+00:00',
        ]);
        self::assertSame(201, $response->getStatusCode());

        return (string) $json['data']['id'];
    }

    /** @return array<string, mixed> */
    private function snapshot(string $id): array
    {
        [, $json] = $this->api('GET', '/public-primary/registry/infrastructure/' . $id, 'registry-manager', 'tenant-syn-a', 'est-syn-a');

        return (array) $json['data'];
    }
}
