<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritorySettings;
use Scholaris\Modules\Socle\Organisation\Domain\SettingScope;
use Scholaris\Modules\Socle\Organisation\Application\SettingsService;
use Tests\Support\Organisation\OrganisationTestCase;

/** PP16 : héritage national > région > département > arrondissement > établissement, avec niveaux de verrouillage. */
final class TerritorySettingsTest extends OrganisationTestCase
{
    private const T = 'tenant-syn-a';
    private const EST_1 = 'est-syn-1';
    private const EST_2 = 'est-syn-2';

    private string $region;
    private string $department;
    private string $areaA;

    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment(self::T, self::EST_1, 'École SYN-1', 'primary', 'inspector');
        $this->seedEstablishment(self::T, self::EST_2, 'École SYN-2', 'primary', 'inspector');
        $this->grantAll('inspector', 'organisation.read', 'organisation.manage', 'organisation.territories.manage');
        $this->region = $this->territory('region', 'SYN-R-01', null);
        $this->department = $this->territory('departement', 'SYN-D-01', $this->region);
        $this->areaA = $this->territory('arrondissement', 'SYN-A-01', $this->department);
        $this->api('PUT', '/territories/' . $this->areaA . '/establishments', 'inspector', self::T, null, [
            'establishment_ids' => [self::EST_1],
            'valid_from' => '2026-01-01T00:00:00+00:00',
        ]);
    }

    public function testUnParametreNationalVerrouilleEstRefuseEnEcritureParUneRegion(): void
    {
        $settings = $this->settings();
        $settings->set(self::T, null, 'calendar.rentree', '2026-09-07', TerritorySettings::NATIONAL, 'national-admin');

        $this->expectStatus(403, fn () => $settings->set(self::T, $this->region, 'calendar.rentree', '2026-09-14', TerritorySettings::REGION, 'region-admin'));
        $this->expectStatus(403, fn () => $settings->set(self::T, $this->areaA, 'calendar.rentree', '2026-09-14', TerritorySettings::DISTRICT, 'area-admin'));
        self::assertSame('2026-09-07', $settings->value(self::T, self::EST_1, 'calendar.rentree'));
    }

    public function testUnCalendrierAjusteParLaRegionEstHeriteParSesEcoles(): void
    {
        $settings = $this->settings();
        $settings->set(self::T, null, 'calendar.rentree', '2026-09-07', TerritorySettings::REGION, 'national-admin');
        $settings->set(self::T, $this->region, 'calendar.rentree', '2026-09-14', TerritorySettings::DEPARTMENT, 'region-admin');

        self::assertSame('2026-09-14', $settings->value(self::T, self::EST_1, 'calendar.rentree'));
        // L'école sans rattachement territorial reste au national.
        self::assertSame('2026-09-07', $settings->value(self::T, self::EST_2, 'calendar.rentree'));
        self::assertSame('defaut', $settings->value(self::T, self::EST_2, 'inconnu', 'defaut'));
    }

    public function testLePlusSpecifiqueGagneSauVerrouEtLEtablissementPeutSurchargerSiLibre(): void
    {
        $settings = $this->settings();
        $settings->set(self::T, null, 'sms.cap', 12, TerritorySettings::ESTABLISHMENT, 'national-admin');
        $settings->set(self::T, $this->department, 'sms.cap', 8, TerritorySettings::ESTABLISHMENT, 'dept-admin');
        $settings->set(self::T, $this->areaA, 'sms.cap', 6, TerritorySettings::ESTABLISHMENT, 'area-admin');
        self::assertSame(6, $settings->value(self::T, self::EST_1, 'sms.cap'));

        $this->kernel->container()->get(SettingsService::class)->set(SettingScope::Establishment, self::T, self::EST_1, 'sms.cap', 4, 'school-admin');
        self::assertSame(4, $settings->value(self::T, self::EST_1, 'sms.cap'));

        // Un verrou posé ensuite écarte la surcharge de l'établissement, sans la supprimer.
        $settings->set(self::T, $this->department, 'sms.cap', 8, TerritorySettings::DEPARTMENT, 'dept-admin');
        self::assertSame(8, $settings->value(self::T, self::EST_1, 'sms.cap'));
    }

    public function testVerrouInvalideTerritoireInconnuEtCleInvalide(): void
    {
        $settings = $this->settings();

        $this->expectStatus(422, fn () => $settings->set(self::T, $this->areaA, 'x.y', 1, TerritorySettings::NATIONAL, 'a'));
        $this->expectStatus(422, fn () => $settings->set(self::T, $this->areaA, 'x.y', 1, 'inconnu', 'a'));
        $this->expectStatus(422, fn () => $settings->set(self::T, null, 'Clé invalide', 1, TerritorySettings::NATIONAL, 'a'));
        $this->expectStatus(404, fn () => $settings->set(self::T, '01J9Z6Q0A1B2C3D4E5F6G7H8J9', 'x.y', 1, TerritorySettings::DISTRICT, 'a'));
        $this->expectStatus(404, fn () => $settings->set('autre-client', $this->areaA, 'x.y', 1, TerritorySettings::DISTRICT, 'a'));
    }

    private function settings(): TerritorySettings
    {
        return $this->kernel->container()->get(TerritorySettings::class);
    }

    private function expectStatus(int $status, callable $call): void
    {
        try {
            $call();
        } catch (HttpException $e) {
            self::assertSame($status, $e->status);

            return;
        }
        self::fail('HttpException ' . $status . ' attendue.');
    }

    private function territory(string $type, string $code, ?string $parentId): string
    {
        [$response, $json] = $this->api('POST', '/territories', 'inspector', self::T, self::EST_1, [
            'type' => $type, 'code' => $code, 'label_fr' => $code, 'label_en' => $code . ' EN', 'parent_id' => $parentId, 'valid_from' => '2025-01-01T00:00:00+00:00',
        ]);
        self::assertSame(201, $response->getStatusCode());

        return (string) $json['data']['id'];
    }
}
