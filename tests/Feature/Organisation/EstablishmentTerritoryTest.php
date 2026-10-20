<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Tests\Support\Organisation\OrganisationTestCase;

/** PP1 : rattachement daté établissement → territoire (RG-TER-1, RG-TER-4). Données SYN-* uniquement. */
final class EstablishmentTerritoryTest extends OrganisationTestCase
{
    private const T = 'tenant-syn-a';
    private const EST_1 = 'est-syn-1';
    private const EST_2 = 'est-syn-2';

    private string $department;
    private string $areaA;
    private string $areaB;

    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment(self::T, self::EST_1, 'École SYN-1', 'primary', 'inspector', 'school-admin');
        $this->seedEstablishment(self::T, self::EST_2, 'École SYN-2', 'primary', 'inspector');
        $this->seedEstablishment('tenant-syn-b', 'est-syn-b', 'École SYN-B', 'primary', 'other-inspector');
        $this->grantAll('inspector', 'organisation.read', 'organisation.manage', 'organisation.territories.manage');
        $this->grantAll('other-inspector', 'organisation.read', 'organisation.manage', 'organisation.territories.manage');
        $this->permissions->grant('school-admin', 'organisation.read', self::EST_1);
        $this->permissions->grant('school-admin', 'organisation.manage', self::EST_1);
        $this->permissions->grant('school-admin', 'organisation.territories.manage', self::EST_1);

        $region = $this->createTerritory('region', 'SYN-R-01', null);
        $this->department = $this->createTerritory('departement', 'SYN-D-01', $region);
        $this->areaA = $this->createTerritory('arrondissement', 'SYN-A-01', $this->department);
        $this->areaB = $this->createTerritory('arrondissement', 'SYN-A-02', $this->department);
    }

    public function testRattachementDateEtChangementDArrondissementEn2027(): void
    {
        [$response, $json] = $this->assign($this->areaA, [self::EST_1, self::EST_2], '2026-01-01T00:00:00+00:00');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->areaA, $json['data']['territory_id']);
        self::assertEqualsCanonicalizing([self::EST_1, self::EST_2], $json['data']['establishment_ids']);

        [$moved] = $this->assign($this->areaB, [self::EST_1], '2027-01-01T00:00:00+00:00');
        self::assertSame(200, $moved->getStatusCode());

        $directory = $this->directory();
        self::assertSame($this->areaA, $directory->territoryOf(self::T, self::EST_1, '2026-06-30T12:00:00+00:00')?->id);
        self::assertSame($this->areaB, $directory->territoryOf(self::T, self::EST_1, '2027-03-01T00:00:00+00:00')?->id);
        self::assertSame($this->areaA, $directory->territoryOf(self::T, self::EST_2, '2027-03-01T00:00:00+00:00')?->id);
        self::assertNull($directory->territoryOf(self::T, self::EST_1, '2025-12-31T23:59:59+00:00'));

        self::assertSame([self::EST_1, self::EST_2], $directory->establishmentsIn(self::T, $this->areaA, '2026-06-30T12:00:00+00:00'));
        self::assertSame([self::EST_2], $directory->establishmentsIn(self::T, $this->areaA, '2027-03-01T00:00:00+00:00'));
        self::assertSame([self::EST_1], $directory->establishmentsIn(self::T, $this->areaB, '2027-03-01T00:00:00+00:00'));
        // Les descendants sont compris : le département voit toutes ses écoles.
        self::assertSame([self::EST_1, self::EST_2], $directory->establishmentsIn(self::T, $this->department, '2027-03-01T00:00:00+00:00'));
        self::assertSame([], $directory->establishmentsIn(self::T, '01J9Z6Q0A1B2C3D4E5F6G7H8J9', '2027-03-01T00:00:00+00:00'));
    }

    public function testLAncienLienEstCloSansEtreReecrit(): void
    {
        $this->assign($this->areaA, [self::EST_1], '2026-01-01T00:00:00+00:00');
        $this->assign($this->areaB, [self::EST_1], '2027-01-01T00:00:00+00:00');

        $rows = $this->linkRows();

        self::assertCount(2, $rows);
        self::assertSame($this->areaA, $rows[0]['territory_id']);
        self::assertSame($rows[1]['valid_from'], $rows[0]['valid_to']);
        self::assertNull($rows[1]['valid_to']);
        self::assertSame(self::T, $rows[0]['tenant_id']);

        // Réécrire le passé est refusé ; répéter le même rattachement ne crée rien.
        [$past] = $this->assign($this->areaA, [self::EST_1], '2026-06-01T00:00:00+00:00');
        self::assertSame(409, $past->getStatusCode());
        $this->assign($this->areaB, [self::EST_1], '2027-06-01T00:00:00+00:00');
        self::assertCount(2, $this->linkRows());
    }

    public function testAutreClientRepond404(): void
    {
        // Un territoire du client A est introuvable pour le client B.
        [$foreignTerritory] = $this->api('PUT', '/territories/' . $this->areaA . '/establishments', 'other-inspector', 'tenant-syn-b', null, [
            'establishment_ids' => ['est-syn-b'],
            'valid_from' => '2026-01-01T00:00:00+00:00',
        ]);
        self::assertSame(404, $foreignTerritory->getStatusCode());

        // Un établissement du client B est introuvable pour le client A.
        [$foreignEstablishment] = $this->assign($this->areaA, ['est-syn-b'], '2026-01-01T00:00:00+00:00');
        self::assertSame(404, $foreignEstablishment->getStatusCode());
        self::assertSame([], $this->linkRows());

        self::assertNull($this->directory()->territoryOf('tenant-syn-b', 'est-syn-b', '2026-06-30T12:00:00+00:00'));
        self::assertSame([], $this->directory()->establishmentsIn('tenant-syn-b', $this->areaA, '2026-06-30T12:00:00+00:00'));
    }

    public function testPermissionDeNiveauClientObligatoire(): void
    {
        // organisation.manage (même client entier) ne suffit pas ; un droit limité à un établissement non plus.
        $this->grantAll('manager-only', 'organisation.read', 'organisation.manage');
        $this->seedEstablishment(self::T, 'est-syn-3', 'École SYN-3', 'primary', 'manager-only');
        [$manager] = $this->api('PUT', '/territories/' . $this->areaA . '/establishments', 'manager-only', self::T, null, [
            'establishment_ids' => [self::EST_1],
            'valid_from' => '2026-01-01T00:00:00+00:00',
        ]);
        self::assertSame(403, $manager->getStatusCode());

        [$schoolLevel] = $this->api('PUT', '/territories/' . $this->areaA . '/establishments', 'school-admin', self::T, null, [
            'establishment_ids' => [self::EST_1],
            'valid_from' => '2026-01-01T00:00:00+00:00',
        ]);
        self::assertSame(403, $schoolLevel->getStatusCode());
        self::assertSame([], $this->linkRows());
    }

    public function testValidationDuCorps(): void
    {
        [$empty] = $this->assign($this->areaA, [], '2026-01-01T00:00:00+00:00');
        self::assertSame(422, $empty->getStatusCode());
        [$badDate] = $this->assign($this->areaA, [self::EST_1], 'pas-une-date');
        self::assertSame(422, $badDate->getStatusCode());
        [$unknown] = $this->api('PUT', '/territories/01J9Z6Q0A1B2C3D4E5F6G7H8J9/establishments', 'inspector', self::T, null, [
            'establishment_ids' => [self::EST_1],
            'valid_from' => '2026-01-01T00:00:00+00:00',
        ]);
        self::assertSame(404, $unknown->getStatusCode());
    }

    /**
     * @param list<string> $establishmentIds
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function assign(string $territoryId, array $establishmentIds, string $validFrom): array
    {
        return $this->api('PUT', '/territories/' . $territoryId . '/establishments', 'inspector', self::T, null, [
            'establishment_ids' => $establishmentIds,
            'valid_from' => $validFrom,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function linkRows(): array
    {
        $statement = $this->connections()->pdo()->query(
            'SELECT territory_id, valid_from, valid_to, tenant_id FROM organisation_establishment_territories ORDER BY valid_from',
        );
        self::assertNotFalse($statement);

        return array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function directory(): TerritoryDirectory
    {
        return $this->kernel->container()->get(TerritoryDirectory::class);
    }

    private function createTerritory(string $type, string $code, ?string $parentId): string
    {
        [$response, $json] = $this->api('POST', '/territories', 'inspector', self::T, self::EST_1, [
            'type' => $type,
            'code' => $code,
            'label_fr' => $code,
            'label_en' => $code . ' EN',
            'parent_id' => $parentId,
            'valid_from' => '2025-01-01T00:00:00+00:00',
        ]);
        self::assertSame(201, $response->getStatusCode());

        return (string) $json['data']['id'];
    }
}
