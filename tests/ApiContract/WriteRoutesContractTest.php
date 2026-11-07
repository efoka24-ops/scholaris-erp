<?php

declare(strict_types=1);

namespace Tests\ApiContract;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiContract\Support\SharedFixtureTestCase;

/**
 * Routes d'écriture (POST, PUT, PATCH) appelées par l'administrateur du client A avec un corps vide : la réponse doit être
 * un statut documenté dans openapi.d, jamais une erreur serveur ; une erreur de validation (422) porte « errors » par champ ;
 * un corps JSON illisible donne 400. Jeu de données propre à cette classe (les écritures acceptées le modifient).
 */
final class WriteRoutesContractTest extends SharedFixtureTestCase
{
    private const SKIP = [
        'POST /api/v1/auth/logout', 'POST /api/v1/auth/not-me', 'POST /api/v1/auth/2fa/setup', 'POST /api/v1/auth/2fa/confirm',
        'POST /api/v1/auth/2fa/verify', 'POST /api/v1/auth/2fa/sms', 'POST /api/v1/auth/2fa/recovery',
    ];

    /** @return array<string, array{0: string, 1: string, 2: bool, 3: bool}> */
    public static function writeRoutes(): array
    {
        return array_filter(self::allRoutes(), static fn (array $r): bool => in_array($r[0], ['POST', 'PUT', 'PATCH'], true));
    }

    #[DataProvider('writeRoutes')]
    public function testUnCorpsVideDonneUnStatutDocumente(string $method, string $path, bool $auth, bool $tenant): void
    {
        $route = $method . ' ' . $path;
        if (in_array($route, self::SKIP, true) || str_contains($path, '/webhooks/') || str_contains($path, '/platform/payments/webhook')) {
            $this->addToAssertionCount(1);

            return;
        }
        $documented = array_map('strval', array_keys((array) (self::openApi()['paths'][$path][strtolower($method)]['responses'] ?? [])));
        $token = $auth ? $this->admin : null;
        $headers = ['Idempotency-Key' => 'contrat-ecriture-' . substr(md5($route), 0, 12)];

        [$response, $json] = $this->raw($method, $this->concretePath($path), $token, $tenant ? $this->school : null, '{}', $headers);
        $status = (string) $response->getStatusCode();

        self::assertNotSame('500', $status, $route . ' : erreur serveur ' . substr((string) $response->getBody(), 0, 300));
        self::assertTrue(in_array($status, $documented, true) || in_array($status, ['401', '403', '429'], true), $route . ' renvoie ' . $status . ', non documenté (' . implode(', ', $documented) . ') : ' . substr((string) $response->getBody(), 0, 200));
        $this->assertSecurityHeaders($response, $route);
        if ((int) $status >= 400) {
            $this->assertProblem($response, $route);
        }
        if ($status === '422') {
            self::assertIsArray($json['errors'] ?? null, $route . ' : 422 sans détail « errors » par champ');
        }

        [$broken] = $this->raw($method, $this->concretePath($path), $token, $tenant ? $this->school : null, '{"non-json', ['Idempotency-Key' => 'contrat-casse-' . substr(md5($route), 0, 12)] + $headers);
        if ($broken->getStatusCode() < 300 && (int) $status < 300) {
            return; // La route n'attend aucun corps : elle l'ignore.
        }
        self::assertContains($broken->getStatusCode(), [400, 401, 403, 404, 409, 415, 422], $route . ' : JSON illisible donne ' . $broken->getStatusCode());
        self::assertNotSame(500, $broken->getStatusCode(), $route);
    }
}
