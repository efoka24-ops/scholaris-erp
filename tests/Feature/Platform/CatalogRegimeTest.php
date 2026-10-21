<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\Support\Platform\PlatformTestCase;

/** Catalogue par niveau et par régime : le module commun n'est pas un produit, le régime désigne l'application technique. */
final class CatalogRegimeTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        $this->bootPlatform();
    }

    public function testLeCatalogueEstOrdonneParNiveauPuisParRegime(): void
    {
        [, $json] = $this->api('GET', '/public/applications');

        self::assertSame(
            ['higher', 'nursery', 'public_nursery', 'primary', 'public_primary', 'secondary'],
            array_column($json['data'], 'key'),
        );
        $byKey = array_column($json['data'], null, 'key');
        self::assertSame(['higher', 'private'], [$byKey['higher']['family'], $byKey['higher']['sector']]);
        self::assertSame(['nursery', 'public'], [$byKey['public_nursery']['family'], $byKey['public_nursery']['sector']]);
        self::assertSame('nursery', $byKey['public_nursery']['target'], 'Champ target conservé (rétrocompatibilité).');
        self::assertSame(['primary', 'public'], [$byKey['public_primary']['family'], $byKey['public_primary']['sector']]);
        self::assertSame('private', $byKey['secondary']['sector']);
        self::assertSame(30000, $byKey['public_primary']['monthly_price_xaf']);
        self::assertSame(60000, $byKey['primary']['monthly_price_xaf']);
    }

    public function testLesLibellesNePortentPasLeRegime(): void
    {
        $byKey = array_column($this->api('GET', '/public/applications')[1]['data'], 'label', 'key');

        self::assertSame('École maternelle', $byKey['nursery']);
        self::assertSame('École maternelle', $byKey['public_nursery']);
        self::assertSame('École primaire', $byKey['primary']);
        self::assertSame('École primaire', $byKey['public_primary']);
    }

    public function testLeModuleCommunEstAbsentDesCataloguesPublicEtClient(): void
    {
        self::assertNotContains('public_schools', array_column($this->api('GET', '/public/applications')[1]['data'], 'key'));

        $client = $this->client('Ecole du Nord');
        [, $json] = $this->api('GET', '/me/applications', 'teacher', $client['tenant']);
        $keys = array_column($json['meta']['catalog'], 'key');
        self::assertNotContains('public_schools', $keys);
        self::assertContains('public_primary', $keys);
        $entry = array_column($json['meta']['catalog'], null, 'key')['public_primary'];
        self::assertSame(['primary', 'public'], [$entry['family'], $entry['sector']]);

        // L'administrateur de plateforme voit toujours la ligne technique, avec son champ listed.
        $admin = array_column($this->api('GET', '/platform/applications', self::ROOT)[1]['data'], null, 'key');
        self::assertFalse($admin['public_schools']['listed']);
        self::assertSame('public', $admin['public_schools']['sector']);
    }

    public function testUneDemandePourLeModuleCommunEstRefusee(): void
    {
        [$response] = $this->raw(
            'POST',
            '/public/access-requests',
            ['Content-Type' => 'application/json'],
            json_encode($this->requestBody(['target_type' => 'primary', 'app_key' => 'public_schools']), JSON_THROW_ON_ERROR),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testLeLibreServiceRefuseUneApplicationNonListee(): void
    {
        $client = $this->client('Ecole du Nord', 'primary');

        [$response] = $this->api('POST', '/applications/public_schools/open', 'boss', $client['tenant'], null, $client['establishment']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testLeSecondaireExigeUnTypeDEtablissementDeLaListeFermee(): void
    {
        $post = fn (array $over): int => $this->raw(
            'POST',
            '/public/access-requests',
            ['Content-Type' => 'application/json'],
            json_encode($this->requestBody($over + ['target_type' => 'secondary', 'app_key' => 'secondary']), JSON_THROW_ON_ERROR),
        )[0]->getStatusCode();

        self::assertSame(422, $post([]), 'Le type manque pour le secondaire.');
        self::assertSame(422, $post(['establishment_subtype' => 'inconnu']));
        self::assertSame(422, $post(['target_type' => 'higher', 'app_key' => 'higher', 'establishment_subtype' => 'leg']), 'Pas de type hors secondaire.');
        self::assertSame(202, $post(['establishment_subtype' => 'cetic']));
    }

    public function testLeTypeEstStockeSurLaDemandeEtSurLEtablissementCree(): void
    {
        $request = $this->submitRequest(['target_type' => 'secondary', 'app_key' => 'secondary', 'establishment_subtype' => 'lycee_technique', 'message' => 'Bonjour']);

        [, $detail] = $this->api('GET', '/platform/access-requests/' . $request['id'], self::ROOT);
        self::assertSame('lycee_technique', $detail['data']['establishment_subtype']);
        self::assertSame('Bonjour', $detail['data']['message'], 'Le type ne passe plus par le message.');

        [$response, $json] = $this->api('POST', '/platform/access-requests/' . $request['id'] . '/accept', self::ROOT, null, ['force_reason' => 'Ouverture pilote du secondaire']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $stored = $this->connections()->pdo()->query("SELECT establishment_subtype FROM establishments WHERE id = '" . $json['data']['establishment_id'] . "'")->fetchColumn();
        self::assertSame('lycee_technique', $stored);
        $info = $this->kernel->container()->get(\Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory::class)->forTenant((string) $json['data']['tenant_id']);
        self::assertSame('lycee_technique', $info[0]->subtype);
    }

    public function testLeRegimeDesigneLApplicationDeLaDemande(): void
    {
        $privee = $this->submitRequest(['target_type' => 'primary', 'app_key' => 'primary', 'contact_email' => 'a@ecole.test']);
        $publique = $this->submitRequest(['target_type' => 'primary', 'app_key' => 'public_primary', 'contact_email' => 'b@ecole.test'], '198.51.100.8');

        $keys = $this->connections()->pdo()->query('SELECT id, app_key FROM platform_access_requests')->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame('primary', $keys[$privee['id']]);
        self::assertSame('public_primary', $keys[$publique['id']]);
    }
}
