<?php

declare(strict_types=1);

namespace Tests\E2E;

use Tests\E2E\Support\BuildsIpes;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Semis de recette (C-117, E4C-E1) : deux semis successifs dans la même base (deux processus de la pile Playwright) obtiennent
 * chacun un administrateur de plateforme connecté, au lieu d'un 409 « numéro déjà utilisé » au second.
 */
final class PlatformSeedIdempotenceTest extends RealStackTestCase
{
    use BuildsIpes;

    protected function setUp(): void
    {
        $this->bootRealStack();
    }

    public function testDeuxSemisDansLaMemeBaseObtiennentChacunUnAdministrateurDePlateforme(): void
    {
        $first = $this->platform();
        $firstLogin = $this->rootLogin();

        // Second processus : même base, aucun état en mémoire.
        $this->root = null;
        $this->platformToken = null;
        $second = $this->platform();

        self::assertNotSame('', $first);
        self::assertNotSame('', $second);
        self::assertNotSame($firstLogin, $this->rootLogin());
        $this->ok('GET', '/platform/access-requests?filter[status]=pending', $second);
    }

    private function rootLogin(): string
    {
        return $this->root['login'] ?? '';
    }
}
