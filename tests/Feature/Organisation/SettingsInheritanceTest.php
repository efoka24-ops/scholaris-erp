<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Application\SettingsService;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Domain\SettingScope;
use Tests\Support\Organisation\OrganisationTestCase;

/** RG-ORG-4 : paramètres à trois niveaux, héritage, surcharge visible et tracée. */
final class SettingsInheritanceTest extends OrganisationTestCase
{
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('t1', 'e1', 'Lycée de Bonanjo', 'secondary', 'alice');
        $this->seedEstablishment('t1', 'e2', 'Collège de Bali', 'secondary', 'alice');
        $this->seedEstablishment('t2', 'e3', 'Autre école', 'primary', 'bob');
        $this->grantAll('alice');
        $this->grantAll('bob');
        $this->settings = $this->kernel->container()->get(SettingsService::class);
    }

    public function testLEtablissementSurchargeLeClientQuiSurchargeLaPlateforme(): void
    {
        $this->settings->set(SettingScope::Platform, 't1', null, 'report.rounding', 2, 'root');
        self::assertSame(SettingScope::Platform, $this->settings->effective('t1', 'e1', 'report.rounding')?->source);

        $this->settings->set(SettingScope::Tenant, 't1', null, 'report.rounding', 1, 'alice');
        $this->settings->set(SettingScope::Establishment, 't1', 'e1', 'report.rounding', 0, 'alice');

        $e1 = $this->settings->effective('t1', 'e1', 'report.rounding');
        self::assertSame(0, $e1?->value);
        self::assertSame(SettingScope::Establishment, $e1->source);
        self::assertSame(
            [[SettingScope::Tenant, 1], [SettingScope::Platform, 2]],
            array_map(static fn ($entry): array => [$entry->scope, $entry->value], $e1->overridden),
            'La surcharge reste visible : les valeurs masquées sont restituées.',
        );

        // Un autre établissement du même client hérite du client ; un autre client hérite de la plateforme.
        $e2 = $this->settings->effective('t1', 'e2', 'report.rounding');
        self::assertSame([1, SettingScope::Tenant], [$e2?->value, $e2->source]);
        $e3 = $this->settings->effective('t2', 'e3', 'report.rounding');
        self::assertSame([2, SettingScope::Platform], [$e3?->value, $e3->source]);
    }

    public function testGetLitUnSeulNiveauEtSetRemplaceLaValeur(): void
    {
        self::assertNull($this->settings->get(SettingScope::Tenant, 't1', null, 'locale.default'));

        $this->settings->set(SettingScope::Tenant, 't1', null, 'locale.default', 'fr', 'alice');
        $this->settings->set(SettingScope::Tenant, 't1', null, 'locale.default', 'en', 'alice');

        self::assertSame('en', $this->settings->get(SettingScope::Tenant, 't1', null, 'locale.default')?->value);
        self::assertNull($this->settings->get(SettingScope::Establishment, 't1', 'e1', 'locale.default'));
        self::assertCount(1, $this->settings->list(SettingScope::Tenant, 't1', null));
        self::assertSame('fr', $this->settings->value('t1', 'e1', 'inconnu', 'fr'), 'Valeur par défaut quand aucun niveau ne définit la clé.');
        self::assertSame('en', $this->kernel->container()->get(SettingsReader::class)->value('t1', 'e1', 'locale.default'));
    }

    public function testChaqueModificationEstTraceeDansLeJournalDAudit(): void
    {
        $this->settings->set(SettingScope::Tenant, 't1', null, 'late.fee', 500, 'alice', '10.0.0.7', 'Firefox');
        $this->settings->set(SettingScope::Establishment, 't1', 'e1', 'late.fee', 1000, 'alice');
        $this->settings->set(SettingScope::Establishment, 't1', 'e1', 'late.fee', 1500, 'alice');

        $rows = $this->connections()->pdo()->query("SELECT * FROM audit_log WHERE tenant_id = 't1' ORDER BY seq")->fetchAll();
        self::assertCount(3, $rows);
        self::assertSame('setting.updated', $rows[0]['action']);
        self::assertSame('tenant:t1:late.fee', $rows[0]['object_id']);
        self::assertSame('alice', $rows[0]['actor_id']);
        self::assertSame('10.0.0.7', $rows[0]['ip']);
        self::assertNull($rows[0]['before_json']);
        self::assertSame(['scope' => 'tenant', 'value' => 500], json_decode((string) $rows[0]['after_json'], true));
        self::assertSame('establishment:e1:late.fee', $rows[2]['object_id']);
        self::assertSame(['scope' => 'establishment', 'value' => 1000], json_decode((string) $rows[2]['before_json'], true));
        self::assertSame(['scope' => 'establishment', 'value' => 1500], json_decode((string) $rows[2]['after_json'], true));
        self::assertTrue($this->kernel->container()->get(AuditLog::class)->verify('t1')->intact);
    }

    public function testUnEtablissementDUnAutreClientNEstJamaisAcceptePourLaSurcharge(): void
    {
        try {
            $this->settings->set(SettingScope::Establishment, 't1', 'e3', 'late.fee', 1, 'alice');
            self::fail('e3 appartient à un autre client.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
        self::assertNull($this->settings->effective('t2', 'e3', 'late.fee'));
    }

    public function testClesInvalidesRefuseesParLeService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set(SettingScope::Tenant, 't1', null, 'Clé invalide', 1, 'alice');
    }

    public function testParApi(): void
    {
        [$response, $body] = $this->api('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'tenant', 'key' => 'fees.reminder_days', 'value' => [7, 14]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('tenant', $body['effective']['source']);

        [, $body] = $this->api('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'establishment', 'key' => 'fees.reminder_days', 'value' => [3]]);
        self::assertSame('establishment', $body['effective']['source']);
        self::assertSame([3], $body['effective']['value']);
        self::assertSame([7, 14], $body['effective']['overridden'][0]['value']);

        [, $e1] = $this->api('GET', '/settings/effective?filter[key]=fees.reminder_days', 'alice', 't1', 'e1');
        [, $e2] = $this->api('GET', '/settings/effective?filter[key]=fees.reminder_days', 'alice', 't1', 'e2');
        self::assertSame([[3], 'establishment'], [$e1['data']['value'], $e1['data']['source']]);
        self::assertSame([[7, 14], 'tenant'], [$e2['data']['value'], $e2['data']['source']]);

        [, $all] = $this->api('GET', '/settings/effective', 'alice', 't1', 'e1');
        self::assertSame(['fees.reminder_days'], array_column($all['data'], 'key'));

        [, $list] = $this->api('GET', '/settings?filter[scope]=tenant', 'alice', 't1', 'e1');
        self::assertSame([[7, 14]], array_column($list['data'], 'value'));
        [, $list] = $this->api('GET', '/settings', 'alice', 't1', 'e2');
        self::assertSame([], $list['data'], 'Par défaut : le niveau établissement, ici vide pour e2.');

        self::assertSame(404, $this->code('GET', '/settings/effective?filter[key]=inconnu', 'alice', 't1', 'e1'));
    }

    public function testLeNiveauPlateformeEstReserve(): void
    {
        $body = ['scope' => 'platform', 'key' => 'fees.reminder_days', 'value' => 5];
        self::assertSame(403, $this->code('PUT', '/settings', 'alice', 't1', 'e1', $body));

        $this->permissions->grant('alice', 'settings.platform');
        self::assertSame(200, $this->code('PUT', '/settings', 'alice', 't1', 'e1', $body));
        self::assertSame(5, $this->settings->get(SettingScope::Platform, 't1', null, 'fees.reminder_days')?->value);
    }

    public function testPermissionsEtValidationDeLApi(): void
    {
        $this->seedEstablishment('t1', 'e4', 'Sans droits', 'secondary', 'carol');
        $this->permissions->grant('carol', 'organisation.read');
        $put = ['scope' => 'tenant', 'key' => 'a.b', 'value' => 1];

        self::assertSame(200, $this->code('GET', '/settings', 'carol', 't1', 'e4'), 'organisation.read suffit pour lire.');
        self::assertSame(403, $this->code('PUT', '/settings', 'carol', 't1', 'e4', $put), 'settings.manage est nécessaire pour écrire.');

        $this->seedEstablishment('t1', 'e5', 'Inconnue', 'secondary', 'dave');
        self::assertSame(403, $this->code('GET', '/settings', 'dave', 't1', 'e5'), 'Refus par défaut.');

        self::assertSame(422, $this->code('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'galaxy', 'key' => 'a.b', 'value' => 1]));
        self::assertSame(422, $this->code('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'tenant', 'key' => 'Mauvaise Clé', 'value' => 1]));
        self::assertSame(422, $this->code('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'tenant', 'key' => 'a.b']), 'La valeur est obligatoire.');
        self::assertSame(422, $this->code('PUT', '/settings', 'alice', 't1', 'e1', $put + ['tenant_id' => 't2']), 'Le client ne vient jamais du corps.');
        self::assertSame(422, $this->code('GET', '/settings?filter[inconnu]=1', 'alice', 't1', 'e1'));
        self::assertSame(422, $this->code('GET', '/settings?filter[scope]=galaxy', 'alice', 't1', 'e1'));
    }

    public function testUnClientNeVoitJamaisLesParametresDUnAutre(): void
    {
        $this->api('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'tenant', 'key' => 'secret.rule', 'value' => 'a']);
        $this->api('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'establishment', 'key' => 'secret.rule', 'value' => 'b']);

        [, $tenant] = $this->api('GET', '/settings?filter[scope]=tenant', 'bob', 't2', 'e3');
        [, $establishment] = $this->api('GET', '/settings', 'bob', 't2', 'e3');
        [, $effective] = $this->api('GET', '/settings/effective', 'bob', 't2', 'e3');

        self::assertSame([], $tenant['data']);
        self::assertSame([], $establishment['data']);
        self::assertSame([], $effective['data']);
        // Bob ne peut pas se servir de l'établissement d'Alice.
        self::assertSame(403, $this->code('GET', '/settings', 'bob', 't2', 'e1'));
    }

    public function testPaginationParCurseur(): void
    {
        foreach (['k.a', 'k.b', 'k.c'] as $key) {
            $this->settings->set(SettingScope::Tenant, 't1', null, $key, 1, 'alice');
        }

        [, $page1] = $this->api('GET', '/settings?filter[scope]=tenant&per_page=2', 'alice', 't1', 'e1');
        self::assertSame(['k.a', 'k.b'], array_column($page1['data'], 'key'));
        self::assertNotNull($page1['meta']['next_cursor']);

        [, $page2] = $this->api('GET', '/settings?filter[scope]=tenant&per_page=2&cursor=' . $page1['meta']['next_cursor'], 'alice', 't1', 'e1');
        self::assertSame(['k.c'], array_column($page2['data'], 'key'));
        self::assertNull($page2['meta']['next_cursor']);
    }
}
