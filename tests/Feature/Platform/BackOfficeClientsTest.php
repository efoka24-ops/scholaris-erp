<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Tests\Support\Platform\PlatformTestCase;

/** Back-office de la plateforme : clients (CRUD et cycle de vie), établissements et administrateurs d'un client. */
final class BackOfficeClientsTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        $this->bootPlatform();
    }

    /** @return array<string, mixed> */
    private function createClient(array $overrides = []): array
    {
        [$response, $json] = $this->api('POST', '/platform/clients', self::ROOT, null, array_replace([
            'name' => 'Institut Polytechnique du Nord',
            'contact_name' => 'Awa Ngo Bassa',
            'contact_email' => 'direction@ipn.test',
            'city' => 'Garoua',
            'region' => 'Nord',
            'establishment_type' => 'higher',
        ], $overrides));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    public function testCreationDirecteModificationEtListe(): void
    {
        $client = $this->createClient();
        self::assertSame('active', $client['state']);
        self::assertSame('Garoua', $client['city']);
        self::assertSame('direction@ipn.test', $client['contact']['email']);

        [$updated, $json] = $this->api('PATCH', '/platform/clients/' . $client['id'], self::ROOT, null, ['name' => 'IPN Garoua', 'region' => 'Nord-Cameroun', 'contact_phone' => '+237 690 11 22 33']);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame('IPN Garoua', $json['data']['name']);
        self::assertSame('Nord-Cameroun', $json['data']['region']);

        [, $show] = $this->api('GET', '/platform/clients/' . $client['id'], self::ROOT);
        self::assertCount(1, $show['data']['establishments']);
        self::assertSame('higher', $show['data']['establishments'][0]['type']);

        [, $list] = $this->api('GET', '/platform/clients', self::ROOT);
        self::assertSame(['IPN Garoua'], array_column($list['data'], 'name'));

        [$invalid] = $this->api('POST', '/platform/clients', self::ROOT, null, ['name' => 'X', 'contact_name' => 'A1']);
        self::assertSame(422, $invalid->getStatusCode());
    }

    public function testSuspensionReactivationEtArchivageTracesSansSuppression(): void
    {
        $client = $this->createClient();
        $tenant = (string) $client['id'];
        [$opened] = $this->api('POST', '/platform/clients/' . $tenant . '/applications', self::ROOT, null, ['app_key' => 'higher']);
        self::assertSame(201, $opened->getStatusCode(), (string) $opened->getBody());

        [$early] = $this->api('POST', '/platform/clients/' . $tenant . '/archive', self::ROOT, null, ['reason' => 'Fin de contrat']);
        self::assertSame(409, $early->getStatusCode(), 'archivage seulement après suspension');

        [$suspended, $json] = $this->api('POST', '/platform/clients/' . $tenant . '/suspend', self::ROOT, null, ['reason' => 'Impayés répétés']);
        self::assertSame(200, $suspended->getStatusCode(), (string) $suspended->getBody());
        self::assertSame('suspended', $json['data']['state']);
        self::assertSame('suspended', $this->entitlement($tenant)['state']);

        [$reactivated, $json] = $this->api('POST', '/platform/clients/' . $tenant . '/reactivate', self::ROOT, null, []);
        self::assertSame(200, $reactivated->getStatusCode(), (string) $reactivated->getBody());
        self::assertSame('active', $json['data']['state']);
        self::assertSame('trial', $this->entitlement($tenant)['state']);

        $this->api('POST', '/platform/clients/' . $tenant . '/suspend', self::ROOT, null, ['reason' => 'Fin de contrat']);
        [$archived, $json] = $this->api('POST', '/platform/clients/' . $tenant . '/archive', self::ROOT, null, ['reason' => 'Fin de contrat']);
        self::assertSame(200, $archived->getStatusCode(), (string) $archived->getBody());
        self::assertSame('archived', $json['data']['state']);
        self::assertSame('closed', $this->entitlement($tenant)['state']);

        [, $list] = $this->api('GET', '/platform/clients', self::ROOT);
        self::assertSame([], $list['data'], 'archivé : absent de la liste par défaut');
        [, $archivedList] = $this->api('GET', '/platform/clients?filter[state]=archived', self::ROOT);
        self::assertSame([$tenant], array_column($archivedList['data'], 'id'));
        [$frozen] = $this->api('PATCH', '/platform/clients/' . $tenant, self::ROOT, null, ['city' => 'Maroua']);
        self::assertSame(409, $frozen->getStatusCode());

        $actions = $this->connections()->pdo()->query("SELECT action FROM audit_log WHERE tenant_id = 'platform' AND action LIKE 'platform.client_%' ORDER BY seq")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['platform.client_created', 'platform.client_suspended', 'platform.client_reactivated', 'platform.client_suspended', 'platform.client_archived'], $actions);
        self::assertSame(1, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM tenants WHERE id = '" . $tenant . "'")->fetchColumn(), 'aucune suppression');
    }

    public function testActionsSensiblesExigentLa2faRecenteEtRestentInterditesAuxClients(): void
    {
        $this->secondFactor->revoke(self::ROOT);
        [$stale, $json] = $this->api('POST', '/platform/clients', self::ROOT, null, ['name' => 'Institut', 'contact_name' => 'Awa', 'contact_email' => 'a@b.test']);
        self::assertSame(403, $stale->getStatusCode());
        self::assertStringContainsString('two-factor-recent-required', (string) $json['type']);

        $other = $this->client('Collège Voisin');
        [$asClient] = $this->api('GET', '/platform/clients', 'boss', $other['tenant']);
        self::assertSame(403, $asClient->getStatusCode());
    }

    public function testEtablissementsCreerRenommerFermerRouvrir(): void
    {
        $tenant = (string) $this->createClient(['establishment_type' => null])['id'];
        [$created, $json] = $this->api('POST', '/platform/clients/' . $tenant . '/establishments', self::ROOT, null, ['name' => 'École primaire annexe', 'type' => 'primary']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = (string) $json['data']['id'];

        [$bad] = $this->api('POST', '/platform/clients/' . $tenant . '/establishments', self::ROOT, null, ['name' => 'Autre', 'type' => 'university']);
        self::assertSame(422, $bad->getStatusCode());
        [$duplicate] = $this->api('POST', '/platform/clients/' . $tenant . '/establishments', self::ROOT, null, ['name' => 'École primaire annexe', 'type' => 'primary']);
        self::assertSame(409, $duplicate->getStatusCode());

        [, $renamed] = $this->api('PATCH', '/platform/clients/' . $tenant . '/establishments/' . $id, self::ROOT, null, ['name' => 'École primaire du Lac']);
        self::assertSame('École primaire du Lac', $renamed['data']['name']);

        [$closed, $json] = $this->api('POST', '/platform/clients/' . $tenant . '/establishments/' . $id . '/close', self::ROOT, null, ['reason' => 'Fusion']);
        self::assertSame(200, $closed->getStatusCode(), (string) $closed->getBody());
        self::assertNotNull($json['data']['closed_at']);
        $directory = $this->kernel->container()->get(EstablishmentDirectory::class);
        self::assertSame([], $directory->forTenant($tenant), 'fermé : plus proposé par l\'annuaire');

        [, $list] = $this->api('GET', '/platform/clients/' . $tenant . '/establishments', self::ROOT);
        self::assertCount(1, $list['data'], 'la plateforme voit encore l\'établissement fermé');
        [$again] = $this->api('POST', '/platform/clients/' . $tenant . '/establishments/' . $id . '/close', self::ROOT, null, ['reason' => 'Fusion']);
        self::assertSame(409, $again->getStatusCode());

        [$reopened] = $this->api('POST', '/platform/clients/' . $tenant . '/establishments/' . $id . '/reopen', self::ROOT, null, []);
        self::assertSame(200, $reopened->getStatusCode());
        self::assertCount(1, $directory->forTenant($tenant));
    }

    public function testAdministrateursDUnClientInviterRenvoyerBloquerReinitialiser(): void
    {
        $tenant = (string) $this->createClient()['id'];
        [$invited, $json] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators', self::ROOT, null, ['first_name' => 'Paul', 'last_name' => 'Biya Mballa', 'email' => 'paul@ipn.test']);
        self::assertSame(201, $invited->getStatusCode(), (string) $invited->getBody());
        self::assertSame('pending', $json['data']['status']);
        $user = (string) $json['data']['id'];
        $invitations = $this->notifier->of('platform_admin_invitation');
        self::assertCount(1, $invitations);
        self::assertSame('paul@ipn.test', $invitations[0]['email']);
        self::assertStringEndsWith('/activation', (string) $invitations[0]['variables']['link']);

        [$noContact] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators', self::ROOT, null, ['first_name' => 'Sans', 'last_name' => 'Contact']);
        self::assertSame(422, $noContact->getStatusCode());

        [$resent] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators/' . $user . '/resend-invitation', self::ROOT, null, []);
        self::assertSame(200, $resent->getStatusCode(), (string) $resent->getBody());
        self::assertCount(2, $this->notifier->of('platform_admin_invitation'));

        [, $blocked] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators/' . $user . '/block', self::ROOT, null, []);
        self::assertSame('locked', $blocked['data']['status']);
        [$resendLocked] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators/' . $user . '/resend-invitation', self::ROOT, null, []);
        self::assertSame(409, $resendLocked->getStatusCode());
        [, $unblocked] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators/' . $user . '/unblock', self::ROOT, null, []);
        self::assertSame('pending', $unblocked['data']['status'], 'jamais activé : revient en attente');

        [, $reset] = $this->api('POST', '/platform/clients/' . $tenant . '/administrators/' . $user . '/reset-access', self::ROOT, null, []);
        self::assertSame('pending', $reset['data']['status']);
        self::assertCount(3, $this->notifier->of('platform_admin_invitation'));

        [, $list] = $this->api('GET', '/platform/clients/' . $tenant . '/administrators', self::ROOT);
        self::assertSame([$user], array_column($list['data'], 'id'));

        $other = $this->client('Collège Voisin');
        [$foreign] = $this->api('POST', '/platform/clients/' . $other['tenant'] . '/administrators/' . $user . '/block', self::ROOT, null, []);
        self::assertSame(404, $foreign->getStatusCode(), 'un compte d\'un autre client est introuvable');
    }
}
