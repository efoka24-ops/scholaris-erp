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

    public function testLeRegimeDesigneLApplicationDeLaDemande(): void
    {
        $privee = $this->submitRequest(['target_type' => 'primary', 'app_key' => 'primary', 'contact_email' => 'a@ecole.test']);
        $publique = $this->submitRequest(['target_type' => 'primary', 'app_key' => 'public_primary', 'contact_email' => 'b@ecole.test'], '198.51.100.8');

        $keys = $this->connections()->pdo()->query('SELECT id, app_key FROM platform_access_requests')->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame('primary', $keys[$privee['id']]);
        self::assertSame('public_primary', $keys[$publique['id']]);
    }
}
