<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Platform\Application\Catalog;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;
use Tests\Support\Platform\PlatformTestCase;

/** Tableau de bord de la plateforme, catalogue (base + manifestes du conteneur) et lanceur /me/applications. */
final class DashboardAndCatalogTest extends PlatformTestCase
{
    public function testLeTableauDeBordNeRapporteQueDesChiffresEnregistres(): void
    {
        $this->bootPlatform();
        [$response, $json] = $this->api('GET', '/platform/dashboard', self::ROOT);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $json['data']['clients']['total']);
        self::assertSame(0, $json['data']['mrr_xaf']['value']);
        self::assertFalse($json['data']['health']['available']);
        self::assertArrayNotHasKey('value', $json['data']['health'], 'Indicateur sans source : aucune valeur inventée.');

        $a = $this->client('Institut A');
        $b = $this->client('Institut B');
        $this->api('POST', '/applications/higher/open', 'boss', $a['tenant'], null, $a['establishment']);
        $this->clock->day('2026-11-01');
        $this->api('POST', '/applications/higher/open', 'boss', $b['tenant'], null, $b['establishment']);
        $this->submitRequest();
        $this->api('POST', '/applications/higher/payments', 'boss', $b['tenant'], ['months' => 3, 'channel' => 'mtn_momo', 'payer_phone' => '+237670000000'], $b['establishment']);
        $provider = (string) $this->connections()->pdo()->query('SELECT provider_reference FROM platform_payments')->fetchColumn();
        $this->webhook('evt-d', 'payment.succeeded', 180000, $provider);

        $this->clock->day('2026-12-25');
        $this->api('PATCH', '/platform/clients/' . $b['tenant'] . '/applications/higher', self::ROOT, null, ['monthly_price_xaf' => 50000]);
        $data = $this->api('GET', '/platform/dashboard', self::ROOT)[1]['data'];

        self::assertSame(2, $data['clients']['total']);
        self::assertSame(1, $data['access_requests_pending']['total']);
        self::assertSame(2, $data['applications']['open_total']);
        $higher = array_values(array_filter($data['applications']['items'], static fn (array $app): bool => $app['key'] === 'higher'))[0];
        $states = $higher['states'];
        ksort($states);
        self::assertSame(['active' => 1, 'trial' => 1], $states);
        self::assertSame(1, $data['trials_ending']['total'], 'Essai de A : fin le 6 janvier, dans moins de 14 jours.');
        self::assertSame('Institut A', $data['trials_ending']['items'][0]['tenant_name']);
        self::assertSame(50000, $data['mrr_xaf']['value'], 'Tarif propre au client pris en compte.');
        self::assertSame(0, $data['unpaid']['total']);
        self::assertSame(0, $data['collected_last_30_days_xaf']['value'], 'Paiement du 1er novembre : hors des 30 derniers jours.');
    }

    public function testLeCatalogueFusionneLaBaseEtLesManifestesDesApplications(): void
    {
        $this->bootPlatform();
        $container = $this->kernel->container();
        $container->set('platform.applications.higher', new ApplicationManifest('higher', 'IPES', 'higher', 'available', '2.3.0', ['people', 'assessment'], 99000));
        $container->set('platform.applications.vocational', [new ApplicationManifest('vocational', 'Formation professionnelle', 'secondary', 'coming_soon', '0.1.0', ['people'], 40000, 60, 10)]);
        $catalog = new Catalog($container->get(\Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb::class), $container, $container->get(\Scholaris\Modules\Socle\Platform\Application\PlatformClock::class));

        $higher = $catalog->findOrFail('higher');
        self::assertSame('2.3.0', $higher['version'], 'Le manifeste fait foi pour la version.');
        self::assertSame(['people', 'assessment'], $higher['required_modules'], 'Et pour les modules requis.');
        self::assertSame(60000, $higher['monthly_price_xaf'], 'La base fait foi pour le tarif.');
        self::assertSame('IPES - Enseignement supérieur', $higher['label']);
        $vocational = $catalog->findOrFail('vocational');
        self::assertSame(40000, $vocational['monthly_price_xaf'], 'Sans ligne en base : valeurs par défaut du manifeste.');
        self::assertSame(60, $vocational['trial_days']);

        $updated = $catalog->update('vocational', ['status' => 'available']);
        self::assertSame('available', $updated['status']);
        self::assertSame(1, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM platform_applications WHERE app_key = 'vocational'")->fetchColumn());
    }

    public function testLAdministrateurModifieLeCatalogue(): void
    {
        $this->bootPlatform();
        [$response, $json] = $this->api('PATCH', '/platform/applications/secondary', self::ROOT, null, ['status' => 'available', 'monthly_price_xaf' => 45000, 'trial_days' => 60]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('available', $json['data']['status']);
        self::assertSame(45000, $json['data']['monthly_price_xaf']);
        self::assertSame(422, $this->api('PATCH', '/platform/applications/secondary', self::ROOT, null, ['status' => 'bientot'])[0]->getStatusCode());
        self::assertSame(422, $this->api('PATCH', '/platform/applications/secondary', self::ROOT, null, ['monthly_price_xaf' => '45000'])[0]->getStatusCode(), 'Montant entier en XAF.');
        self::assertSame(404, $this->api('PATCH', '/platform/applications/inconnue', self::ROOT, null, ['status' => 'available'])[0]->getStatusCode());

        $this->api('PATCH', '/platform/applications/nursery', self::ROOT, null, ['status' => 'retired']);
        self::assertNotContains('nursery', array_column($this->api('GET', '/public/applications')[1]['data'], 'key'));
    }

    public function testLeLanceurMontreLesApplicationsOuvertesAuxEtablissementsDeLUtilisateur(): void
    {
        $this->bootPlatform();
        $ipes = $this->client('IPES du Nord');
        $this->api('POST', '/applications/higher/open', 'boss', $ipes['tenant'], null, $ipes['establishment']);
        $this->clock->day('2026-11-08');

        [$response, $json] = $this->api('GET', '/me/applications', 'teacher', $ipes['tenant']);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $json['data']);
        self::assertSame('higher', $json['data'][0]['app_key']);
        self::assertSame(60, $json['data'][0]['trial_days_left']);
        self::assertSame('IPES du Nord', $json['data'][0]['establishments'][0]['name']);
        self::assertNotContains('higher', array_column($json['meta']['catalog'], 'key'));
        self::assertContains('secondary', array_column($json['meta']['catalog'], 'key'));

        self::assertSame([], $this->api('GET', '/me/applications', 'sans-role', $ipes['tenant'])[1]['data']);
    }
}
