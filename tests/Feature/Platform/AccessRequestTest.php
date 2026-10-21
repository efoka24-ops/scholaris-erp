<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Tests\Support\Platform\PlatformTestCase;

/** Demande d'accès publique : anti-abus, suivi, traitement par l'administrateur de plateforme. */
final class AccessRequestTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        $this->bootPlatform();
    }

    public function testLeCataloguePublicListeLesApplicationsNonRetirees(): void
    {
        [$response, $json] = $this->api('GET', '/public/applications');

        self::assertSame(200, $response->getStatusCode());
        $keys = array_column($json['data'], 'key');
        self::assertSame(['higher', 'nursery', 'public_nursery', 'primary', 'public_primary', 'secondary'], $keys, 'Par niveau puis régime ; le module commun public_schools n\'est pas un produit.');
        $available = array_column(array_filter($json['data'], static fn (array $app): bool => $app['status'] === 'available'), 'key');
        self::assertSame(['higher'], $available, 'Les applications publiques restent « bientôt » tant qu\'elles ne sont pas terminées (T610).');
        self::assertSame(60000, $json['data'][0]['monthly_price_xaf']);
        self::assertSame(90, $json['data'][0]['trial_days']);
    }

    public function testUneDemandeEstEnregistreeNotifieeEtSuiviePublicLeJeton(): void
    {
        $request = $this->submitRequest();

        $received = $this->notifier->of('platform_access_request_received');
        self::assertCount(1, $received);
        self::assertSame('direction@isl.test', $received[0]['email']);
        self::assertStringContainsString('/demande-acces/' . $request['token'], (string) $received[0]['variables']['link']);

        [$response, $json] = $this->api('GET', '/public/access-requests/' . $request['token']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pending', $json['data']['status']);
        self::assertSame('Institut Supérieur du Littoral', $json['data']['institution_name']);
        self::assertStringNotContainsString('direction@isl.test', (string) json_encode($json));

        $stored = $this->connections()->pdo()->query('SELECT token_hash, contact_phone FROM platform_access_requests')->fetch();
        self::assertSame(hash('sha256', $request['token']), $stored['token_hash'], 'Seule l\'empreinte du jeton est conservée.');
        self::assertSame('+237690000001', $stored['contact_phone']);

        self::assertSame(404, $this->api('GET', '/public/access-requests/' . str_repeat('a', 48))[0]->getStatusCode());
        self::assertSame(404, $this->api('GET', '/public/access-requests/pas-un-jeton')[0]->getStatusCode());
    }

    public function testLeChampPiegeRenvoieUneReponseLeurreSansRienEnregistrer(): void
    {
        [$response, $json] = $this->raw('POST', '/public/access-requests', ['Content-Type' => 'application/json'], json_encode($this->requestBody(['website' => 'http://spam.test']), JSON_THROW_ON_ERROR));

        self::assertSame(202, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $json['data']['tracking_token']);
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM platform_access_requests')->fetchColumn());
        self::assertSame([], $this->notifier->sent);
        self::assertSame(404, $this->api('GET', '/public/access-requests/' . $json['data']['tracking_token'])[0]->getStatusCode());
    }

    public function testLaValidationEstStricte(): void
    {
        $cases = [
            'champ inconnu' => [['role' => 'super_admin'], 'role'],
            'e-mail invalide' => [['contact_email' => 'pas-une-adresse'], 'contact_email'],
            'balise' => [['institution_name' => '<script>alert(1)</script>'], 'institution_name'],
            'type inconnu' => [['target_type' => 'university'], 'target_type'],
            'téléphone' => [['contact_phone' => '12ab'], 'contact_phone'],
            'nom avec chiffres' => [['contact_last_name' => 'R2D2'], 'contact_last_name'],
            'application pour une autre cible' => [['target_type' => 'primary'], 'app_key'],
        ];
        $n = 10;
        foreach ($cases as $label => [$override, $field]) {
            [$response, $json] = $this->raw('POST', '/public/access-requests', ['Content-Type' => 'application/json'], json_encode($this->requestBody($override + ['contact_email' => 'cas-' . md5($label) . '@isl.test']), JSON_THROW_ON_ERROR), '203.0.113.' . $n++);
            self::assertSame(422, $response->getStatusCode(), $label . ' : ' . $response->getBody());
            self::assertArrayHasKey($field, $json['errors'] ?? [], $label);
        }
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM platform_access_requests')->fetchColumn());
    }

    public function testLimiteDeDebitParAdresseIp(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->submitRequest(['contact_email' => "contact{$i}@isl.test"], '192.0.2.50');
        }
        [$response] = $this->raw('POST', '/public/access-requests', ['Content-Type' => 'application/json'], json_encode($this->requestBody(['contact_email' => 'contact6@isl.test']), JSON_THROW_ON_ERROR), '192.0.2.50');

        self::assertSame(429, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Retry-After'));
        $this->submitRequest(['contact_email' => 'contact6@isl.test'], '192.0.2.51');
    }

    public function testLimiteDeDebitParEmailEtReponseIdentiqueQueLAdresseSoitConnueOuNon(): void
    {
        $first = $this->raw('POST', '/public/access-requests', ['Content-Type' => 'application/json'], json_encode($this->requestBody(), JSON_THROW_ON_ERROR), '192.0.2.1');
        $second = $this->raw('POST', '/public/access-requests', ['Content-Type' => 'application/json'], json_encode($this->requestBody(), JSON_THROW_ON_ERROR), '192.0.2.2');
        self::assertSame(202, $first[0]->getStatusCode());
        self::assertSame(202, $second[0]->getStatusCode());
        self::assertSame(array_keys($first[1]['data']), array_keys($second[1]['data']));
        self::assertSame($first[1]['data']['message'], $second[1]['data']['message']);

        $this->raw('POST', '/public/access-requests', ['Content-Type' => 'application/json'], json_encode($this->requestBody(), JSON_THROW_ON_ERROR), '192.0.2.3');
        [$response] = $this->raw('POST', '/public/access-requests', ['Content-Type' => 'application/json'], json_encode($this->requestBody(), JSON_THROW_ON_ERROR), '192.0.2.4');
        self::assertSame(429, $response->getStatusCode());
    }

    public function testAccepterCreeLeClientLEtablissementLEssaiEtInviteLePremierAdministrateur(): void
    {
        $request = $this->submitRequest();

        [$response, $json] = $this->api('POST', '/platform/access-requests/' . $request['id'] . '/accept', self::ROOT, null, []);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $json['data'];
        self::assertSame('accepted', $data['status']);
        $tenant = (string) $data['tenant_id'];
        self::assertTrue($this->kernel->container()->get(ClientProvisioning::class)->tenantExists($tenant));

        $establishments = $this->kernel->container()->get(EstablishmentDirectory::class)->forTenant($tenant);
        self::assertCount(1, $establishments);
        self::assertSame('higher', $establishments[0]->type);
        self::assertSame($data['establishment_id'], $establishments[0]->id);

        self::assertSame('trial', $data['entitlement']['state']);
        self::assertSame('2026-10-09', $data['entitlement']['started_on']);
        self::assertSame('2027-01-06', $data['entitlement']['trial_ends_on'], 'Essai de 90 jours, jour d\'ouverture compris.');
        self::assertSame(90, $data['entitlement']['trial_days_left']);
        self::assertSame('access_request', $data['entitlement']['source']);

        self::assertSame('pending_activation', $data['administrator']['status']);
        $role = $this->connections()->pdo()->prepare('SELECT role FROM role_assignments WHERE tenant_id = ? AND person_id = ?');
        $role->execute([$tenant, $data['administrator']['person_id']]);
        self::assertSame('super_admin', $role->fetchColumn());

        $accepted = $this->notifier->of('platform_access_request_accepted');
        self::assertCount(1, $accepted);
        self::assertSame('+237690000001', $accepted[0]['phone']);
        self::assertSame('06/01/2027', $accepted[0]['variables']['due_on']);
        self::assertSame('https://app.scholaris.test/activation', $accepted[0]['variables']['link']);

        $audit = $this->connections()->pdo()->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = ?');
        $audit->execute(['platform', 'platform.access_request_accepted']);
        self::assertSame(1, (int) $audit->fetchColumn());
        $audit->execute([$tenant, 'platform.application_opened']);
        self::assertSame(1, (int) $audit->fetchColumn());

        self::assertSame('accepted', $this->api('GET', '/public/access-requests/' . $request['token'])[1]['data']['status']);
        self::assertSame(409, $this->api('POST', '/platform/access-requests/' . $request['id'] . '/accept', self::ROOT, null, [])[0]->getStatusCode());
    }

    public function testAccepterEnRattachantAUnClientExistantNInvitePasDAdministrateur(): void
    {
        $existing = $this->kernel->container()->get(ClientProvisioning::class)->createTenant('Groupe ISL');
        $request = $this->submitRequest();

        [$response, $json] = $this->api('POST', '/platform/access-requests/' . $request['id'] . '/accept', self::ROOT, null, ['tenant_id' => $existing]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($existing, $json['data']['tenant_id']);
        self::assertNull($json['data']['administrator']);
        self::assertSame('trial', $this->entitlement($existing)['state']);

        $other = $this->submitRequest(['contact_email' => 'autre@isl.test']);
        self::assertSame(422, $this->api('POST', '/platform/access-requests/' . $other['id'] . '/accept', self::ROOT, null, ['tenant_id' => 'inconnu'])[0]->getStatusCode());
    }

    /** F2 : une demande d'accès n'ouvre pas en essai une application à venir ou retirée, sauf forçage motivé et tracé. */
    public function testApplicationAVenirOuRetireeRefuseeSaufForcageMotive(): void
    {
        $soon = $this->submitRequest(['app_key' => 'secondary', 'target_type' => 'secondary', 'establishment_subtype' => 'college_prive', 'institution_name' => 'Collège Pilote', 'contact_email' => 'pilote@college.test']);
        [$refused, $refusedJson] = $this->api('POST', '/platform/access-requests/' . $soon['id'] . '/accept', self::ROOT, null, []);
        self::assertSame(422, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertStringContainsString('pas encore disponible', $refusedJson['errors']['app_key'][0]);
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM tenant_applications')->fetchColumn(), 'Rien n\'est créé avant le refus.');
        self::assertSame('pending', $this->api('GET', '/public/access-requests/' . $soon['token'])[1]['data']['status']);

        [$forced, $forcedJson] = $this->api('POST', '/platform/access-requests/' . $soon['id'] . '/accept', self::ROOT, null, ['force_reason' => 'Établissement pilote validé par la direction.']);
        self::assertSame(200, $forced->getStatusCode(), (string) $forced->getBody());
        self::assertSame('trial', $forcedJson['data']['entitlement']['state']);
        $audit = $this->connections()->pdo()->prepare('SELECT after_json FROM audit_log WHERE tenant_id = ? AND action = ?');
        $audit->execute([(string) $forcedJson['data']['tenant_id'], 'platform.application_opened']);
        self::assertStringContainsString('pilote', (string) $audit->fetchColumn(), 'Le motif du forçage est tracé.');

        $retired = $this->submitRequest(['contact_email' => 'retire@isl.test']);
        self::assertSame(200, $this->api('PATCH', '/platform/applications/higher', self::ROOT, null, ['status' => 'retired'])[0]->getStatusCode());
        [$gone, $goneJson] = $this->api('POST', '/platform/access-requests/' . $retired['id'] . '/accept', self::ROOT, null, []);
        self::assertSame(422, $gone->getStatusCode());
        self::assertStringContainsString('retirée', $goneJson['errors']['app_key'][0]);
        [$blank] = $this->api('POST', '/platform/access-requests/' . $retired['id'] . '/accept', self::ROOT, null, ['force_reason' => '   ']);
        self::assertSame(422, $blank->getStatusCode(), 'Un motif vide ne force rien.');
    }

    public function testRefuserExigeUnMotifTransmisAuDemandeur(): void
    {
        $request = $this->submitRequest();

        self::assertSame(422, $this->api('POST', '/platform/access-requests/' . $request['id'] . '/reject', self::ROOT, null, [])[0]->getStatusCode());
        [$response, $json] = $this->api('POST', '/platform/access-requests/' . $request['id'] . '/reject', self::ROOT, null, ['reason' => 'Autorisation ministérielle introuvable.']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rejected', $json['data']['status']);
        self::assertSame('Autorisation ministérielle introuvable.', $this->notifier->of('platform_access_request_rejected')[0]['variables']['details']);
        $tracked = $this->api('GET', '/public/access-requests/' . $request['token'])[1]['data'];
        self::assertSame('Autorisation ministérielle introuvable.', $tracked['decision_reason']);
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM tenants')->fetchColumn());
    }

    public function testDemanderUnComplementPuisLeDemandeurRepond(): void
    {
        $request = $this->submitRequest();

        [$response, $json] = $this->api('POST', '/platform/access-requests/' . $request['id'] . '/request-info', self::ROOT, null, ['message' => 'Joignez la référence de votre arrêté d\'ouverture.']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('info_requested', $json['data']['status']);
        self::assertCount(1, $this->notifier->of('platform_access_request_info'));

        $tracked = $this->api('GET', '/public/access-requests/' . $request['token'])[1]['data'];
        self::assertSame('Joignez la référence de votre arrêté d\'ouverture.', $tracked['info_request']);

        [$response, $json] = $this->raw('POST', '/public/access-requests/' . $request['token'] . '/complement', ['Content-Type' => 'application/json'], json_encode(['message' => 'Arrêté n° 24/0123 du 12 mars 2024.'], JSON_THROW_ON_ERROR));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('pending', $json['data']['status']);
        self::assertSame(409, $this->raw('POST', '/public/access-requests/' . $request['token'] . '/complement', ['Content-Type' => 'application/json'], json_encode(['message' => 'Encore.'], JSON_THROW_ON_ERROR))[0]->getStatusCode());

        $detail = $this->api('GET', '/platform/access-requests/' . $request['id'], self::ROOT)[1]['data'];
        self::assertSame('Arrêté n° 24/0123 du 12 mars 2024.', $detail['complement']);
    }

    public function testListeFiltreeEtPaginee(): void
    {
        $a = $this->submitRequest(['contact_email' => 'a@isl.test']);
        $this->submitRequest(['contact_email' => 'b@isl.test']);
        $this->submitRequest(['contact_email' => 'c@isl.test']);
        $this->api('POST', '/platform/access-requests/' . $a['id'] . '/reject', self::ROOT, null, ['reason' => 'Doublon.']);

        [$response, $json] = $this->api('GET', '/platform/access-requests?filter[status]=pending&per_page=1', self::ROOT);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $json['data']);
        self::assertNotNull($json['meta']['next_cursor']);
        $next = $this->api('GET', '/platform/access-requests?filter[status]=pending&per_page=1&cursor=' . $json['meta']['next_cursor'], self::ROOT)[1];
        self::assertCount(1, $next['data']);
        self::assertNull($next['meta']['next_cursor']);
        self::assertSame(422, $this->api('GET', '/platform/access-requests?filter[status]=bidon', self::ROOT)[0]->getStatusCode());
    }
}
