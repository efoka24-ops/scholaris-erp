<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Core;

use Tests\Support\Higher\Academic\AcademicTestCase;

/**
 * Chaque lecture Higher qui prend un identifiant (`GET /higher/.../{id}`) répond proprement (404 ou refus) à un identifiant
 * inexistant, au lieu de faire planter PHP. Cas d'origine : `GET /higher/tutelages/x1` (segmentation fault sur PHP 8.1, voir
 * tests/Architecture/NoNestedFirstClassCallableTest.php). La personne a toutes les permissions Higher : les contrôleurs sont
 * réellement atteints. Le tableau est tiré du registre des routes : il suit les sous-modules futurs.
 */
final class UnknownIdentifierRoutesTest extends AcademicTestCase
{
    protected function setUp(): void
    {
        $this->bootAcademic();
        $this->grantAll(self::T, self::ADMIN, self::E, self::higherPermissions());
    }

    /** @return list<string> toutes les permissions citées par le code Higher */
    private static function higherPermissions(): array
    {
        $permissions = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/src/Modules/Apps/Higher', \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php'
                && preg_match_all("/'(higher\.[a-z0-9_]+(?:\.[a-z0-9_]+)+)'/", (string) file_get_contents($file->getPathname()), $matches) > 0) {
                array_push($permissions, ...$matches[1]);
            }
        }

        return array_values(array_unique($permissions));
    }

    public function testLaTutelleInexistanteRepond404(): void
    {
        [$status, $problem] = $this->call('GET', '/higher/tutelages/01HZZZZZZZZZZZZZZZZZZZZZZZ');

        self::assertSame(404, $status);
        self::assertSame('Tutelle introuvable.', $problem['detail'] ?? null);
    }

    public function testChaqueLectureAIdentifiantRepondSansPlanterAUnIdentifiantInexistant(): void
    {
        $checked = 0;
        foreach ($this->kernel->routes()->all() as $route) {
            if ($route['method'] !== 'GET' || !str_starts_with($route['path'], '/api/v1/higher/') || !str_contains($route['path'], '{')) {
                continue;
            }
            $path = (string) preg_replace('#\{[^}]+\}#', '01HZZZZZZZZZZZZZZZZZZZZZZZ', substr($route['path'], strlen('/api/v1')));
            [$status, $body] = $this->call('GET', $path);
            self::assertContains($status, [200, 400, 403, 404, 409, 422], 'GET ' . $path . ' : ' . json_encode($body, JSON_UNESCAPED_UNICODE));
            ++$checked;
        }

        self::assertGreaterThan(30, $checked, 'Le registre doit fournir les lectures à identifiant de tous les sous-modules Higher.');
    }

    /** Même défaut sur les listes : `json(Listing::of(..., X::m(...)))` plantait quand la liste refusait ses paramètres (422). */
    public function testChaqueListeRefuseProprementDesParametresInvalides(): void
    {
        $checked = 0;
        foreach ($this->kernel->routes()->all() as $route) {
            if ($route['method'] !== 'GET' || !str_starts_with($route['path'], '/api/v1/higher/') || str_contains($route['path'], '{')) {
                continue;
            }
            $path = substr($route['path'], strlen('/api/v1')) . '?filter[inconnu]=1&per_page=abc&cursor=%%%';
            [$status, $body] = $this->call('GET', $path);
            self::assertLessThan(500, $status, 'GET ' . $path . ' : ' . json_encode($body, JSON_UNESCAPED_UNICODE));
            ++$checked;
        }

        self::assertGreaterThan(30, $checked);
    }
}
