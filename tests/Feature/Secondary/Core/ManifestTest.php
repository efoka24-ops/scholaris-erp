<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Core;

use Scholaris\Modules\Apps\Secondary\SecondaryApplication;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;
use Tests\Support\Secondary\SecondaryTestCase;

/** T810 : un seul manifeste `secondary`, `coming_soon`, visible au catalogue public ; tarif de base inchangé (60 000 XAF/mois). */
final class ManifestTest extends SecondaryTestCase
{
    protected function setUp(): void
    {
        $this->bootSecondary();
    }

    public function testUnSeulManifesteSecondaireComingSoon(): void
    {
        $manifest = $this->kernel->container()->get(SecondaryApplication::CONTAINER_KEY);
        self::assertInstanceOf(ApplicationManifest::class, $manifest);
        self::assertSame('secondary', $manifest->key);
        self::assertSame('secondary', $manifest->target);
        self::assertSame(ApplicationManifest::COMING_SOON, $manifest->status);
        self::assertSame(60000, $manifest->monthlyPriceXaf);
        self::assertSame([90, 15], [$manifest->trialDays, $manifest->graceDays]);
    }

    public function testLeProduitApparaitUneSeuleFoisAuCataloguePublic(): void
    {
        $response = $this->raw('GET', '/public/applications');
        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true);
        self::assertIsArray($json);
        $secondary = array_values(array_filter((array) ($json['data'] ?? []), static fn (mixed $app): bool => is_array($app) && ($app['key'] ?? null) === 'secondary'));
        self::assertCount(1, $secondary, 'Un seul produit Enseignement secondaire au catalogue.');
        self::assertSame('coming_soon', $secondary[0]['status']);
        $keys = array_column((array) $json['data'], 'key');
        self::assertNotContains('public_secondary', $keys, 'Aucune application « secondaire public » (D14).');
    }
}
