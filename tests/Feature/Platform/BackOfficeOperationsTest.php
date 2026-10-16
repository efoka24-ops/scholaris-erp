<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;
use Tests\Support\Platform\PlatformTestCase;

/** Back-office : file des demandes (nouvelles en tête, archivage, suppression), paiements manuels, administrateurs de plateforme, journal. */
final class BackOfficeOperationsTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        $this->bootPlatform();
    }

    public function testUneDemandeRecenteApparaitEnPremierePageEtUneDemandeDeTestSArchivePuisSeSupprime(): void
    {
        // Diagnostic « on ne voit rien » : la liste était triée de la plus ancienne à la plus récente ; au-delà d'une page,
        // une nouvelle demande n'apparaissait jamais sans « Voir plus ». Elle doit être en tête.
        $this->submitRequest(['contact_email' => 'a@one.test', 'institution_name' => 'Institut Ancien']);
        $this->submitRequest(['contact_email' => 'b@two.test', 'institution_name' => 'Institut Moyen'], '198.51.100.8');
        $foka = $this->submitRequest(['contact_email' => 'emm@foka.test', 'institution_name' => 'ETS FOKA'], '198.51.100.9');

        [$response, $page] = $this->api('GET', '/platform/access-requests?filter[status]=pending&per_page=1', self::ROOT);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ETS FOKA', $page['data'][0]['institution_name']);
        [, $second] = $this->api('GET', '/platform/access-requests?filter[status]=pending&per_page=1&cursor=' . rawurlencode((string) $page['meta']['next_cursor']), self::ROOT);
        self::assertSame('Institut Moyen', $second['data'][0]['institution_name']);

        [$cannotDelete] = $this->api('DELETE', '/platform/access-requests/' . $foka['id'], self::ROOT);
        self::assertSame(409, $cannotDelete->getStatusCode(), 'une demande non archivée ne se supprime pas');

        [$archived, $json] = $this->api('POST', '/platform/access-requests/' . $foka['id'] . '/archive', self::ROOT, null, ['reason' => 'Demande de test']);
        self::assertSame(200, $archived->getStatusCode(), (string) $archived->getBody());
        self::assertNotNull($json['data']['archived_at']);
        [, $pending] = $this->api('GET', '/platform/access-requests?filter[status]=pending', self::ROOT);
        self::assertNotContains('ETS FOKA', array_column($pending['data'], 'institution_name'));
        [, $archivedList] = $this->api('GET', '/platform/access-requests?filter[archived]=1', self::ROOT);
        self::assertSame(['ETS FOKA'], array_column($archivedList['data'], 'institution_name'));
        [, $dashboard] = $this->api('GET', '/platform/dashboard', self::ROOT);
        self::assertSame(2, $dashboard['data']['access_requests_pending']['total']);
        [$acceptArchived] = $this->api('POST', '/platform/access-requests/' . $foka['id'] . '/accept', self::ROOT, null, []);
        self::assertSame(409, $acceptArchived->getStatusCode());

        [$deleted] = $this->api('DELETE', '/platform/access-requests/' . $foka['id'], self::ROOT);
        self::assertSame(200, $deleted->getStatusCode());
        self::assertSame(404, $this->api('GET', '/platform/access-requests/' . $foka['id'], self::ROOT)[0]->getStatusCode());
        $logged = $this->connections()->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE tenant_id = 'platform' AND action = 'platform.access_request_deleted'")->fetchColumn();
        self::assertSame(1, (int) $logged);
    }

    public function testPaiementManuelAvecRecuPuisAnnulationMotivee(): void
    {
        $client = $this->client('Institut du Littoral');
        $tenant = $client['tenant'];
        $this->api('POST', '/platform/clients/' . $tenant . '/applications', self::ROOT, null, ['app_key' => 'higher']);
        $trialEnd = (string) $this->entitlement($tenant)['trial_ends_on'];

        [$bad] = $this->api('POST', '/platform/payments', self::ROOT, null, ['tenant_id' => $tenant, 'app_key' => 'higher', 'months' => 1, 'channel' => 'mtn_momo']);
        self::assertSame(422, $bad->getStatusCode(), 'mobile money : jamais enregistré à la main');

        [$recorded, $json] = $this->api('POST', '/platform/payments', self::ROOT, null, ['tenant_id' => $tenant, 'app_key' => 'higher', 'months' => 2, 'channel' => 'cash', 'amount_xaf' => 120000, 'note' => 'Reçu papier n° 12']);
        self::assertSame(201, $recorded->getStatusCode(), (string) $recorded->getBody());
        self::assertSame('succeeded', $json['data']['status']);
        self::assertNotNull($json['data']['receipt_number']);
        $paymentId = (string) $json['data']['id'];
        self::assertSame('active', $this->entitlement($tenant)['state']);
        self::assertGreaterThan($trialEnd, (string) $this->entitlement($tenant)['paid_until']);
        self::assertCount(0, $this->notifier->of('platform_payment_receipt'), 'pas de contact enregistré pour ce client de test');

        [, $receipt] = $this->api('GET', '/platform/payments/' . $paymentId . '/receipt', self::ROOT);
        self::assertSame(120000, $receipt['data']['amount_xaf']);
        self::assertSame('Institut du Littoral', $receipt['data']['tenant_name']);

        [$noReason] = $this->api('POST', '/platform/payments/' . $paymentId . '/cancel', self::ROOT, null, []);
        self::assertSame(422, $noReason->getStatusCode());
        [$cancelled, $json] = $this->api('POST', '/platform/payments/' . $paymentId . '/cancel', self::ROOT, null, ['reason' => 'Chèque sans provision']);
        self::assertSame(200, $cancelled->getStatusCode(), (string) $cancelled->getBody());
        self::assertSame('cancelled', $json['data']['status']);
        self::assertNull($this->entitlement($tenant)['paid_until']);
        self::assertSame('trial', $this->entitlement($tenant)['state']);
        [$twice] = $this->api('POST', '/platform/payments/' . $paymentId . '/cancel', self::ROOT, null, ['reason' => 'Encore']);
        self::assertSame(409, $twice->getStatusCode());
    }

    public function testAdministrateursDePlateformeJamaisSoiMemeNiLeDernier(): void
    {
        $creator = $this->kernel->container()->get(PlatformAdministrators::class);
        $first = $creator->create('premier@scholaris.test', 'Emmanuel', 'Foka');
        $second = $creator->create('second@scholaris.test', 'Awa', 'Ngo');

        [$invited, $json] = $this->api('POST', '/platform/administrators', self::ROOT, null, ['first_name' => 'Jean', 'last_name' => 'Kamga', 'email' => 'jean@scholaris.test']);
        self::assertSame(201, $invited->getStatusCode(), (string) $invited->getBody());
        self::assertSame('pending', $json['data']['status']);
        [, $list] = $this->api('GET', '/platform/administrators', self::ROOT);
        self::assertCount(3, $list['data']);

        // L'acteur est le premier administrateur : il ne peut pas se désactiver lui-même.
        $this->permissions->grant($first['user_id'], 'platform', 'platform.access', 'platform.admins.manage');
        $this->secondFactor->allow($first['user_id']);
        [$self] = $this->api('POST', '/platform/administrators/' . $first['user_id'] . '/block', $first['user_id']);
        self::assertSame(409, $self->getStatusCode());

        [$blocked, $json] = $this->api('POST', '/platform/administrators/' . $second['user_id'] . '/block', $first['user_id']);
        self::assertSame(200, $blocked->getStatusCode(), (string) $blocked->getBody());
        self::assertSame('locked', $json['data']['status']);

        // Seul le premier reste actif : le désactiver (par un autre acteur) laisserait la plateforme sans administrateur.
        [$last] = $this->api('POST', '/platform/administrators/' . $first['user_id'] . '/block', self::ROOT);
        self::assertSame(409, $last->getStatusCode());
    }

    public function testJournalDeLaPlateformeFiltreEtPagine(): void
    {
        $this->submitRequest();
        $this->api('POST', '/platform/clients', self::ROOT, null, ['name' => 'Institut Témoin', 'contact_name' => 'Awa', 'contact_email' => 'a@temoin.test']);

        [$response, $json] = $this->api('GET', '/platform/audit?filter[action]=platform.client&per_page=5', self::ROOT);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['platform.client_created'], array_column($json['data'], 'action'));
        self::assertSame('Institut Témoin', $json['data'][0]['details']['name']);

        [, $all] = $this->api('GET', '/platform/audit?per_page=1', self::ROOT);
        self::assertCount(1, $all['data']);
        self::assertNotNull($all['meta']['next_cursor']);
        [$badDate] = $this->api('GET', '/platform/audit?filter[from]=hier', self::ROOT);
        self::assertSame(422, $badDate->getStatusCode());
    }
}
