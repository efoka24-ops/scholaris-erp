<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AppTestCase;

final class EstablishmentAccessTest extends AppTestCase
{
    protected function setUp(): void
    {
        $this->boot();
    }

    public function testEnTeteEtablissementObligatoire(): void
    {
        $response = $this->call('GET', '/api/v1/_test/things', ['Authorization' => 'Bearer alice:t1']);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testEtablissementNonAutoriseRenvoie403(): void
    {
        $response = $this->call('GET', '/api/v1/_test/things', ['Authorization' => 'Bearer alice:t1', 'X-Establishment-Id' => 'e1']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testEtablissementAutoriseRenvoie200EtCloisonneParClient(): void
    {
        $this->access->grant('alice', 'e1');
        $this->access->grant('bob', 'e1');
        $this->call('GET', '/api/v1/_test/things', ['Authorization' => 'Bearer alice:t1', 'X-Establishment-Id' => 'e1']);
        $pdo = $this->connections()->pdo();
        $pdo->exec("INSERT INTO things VALUES ('A', 't1', 'e1', 'à Alice')");
        $pdo->exec("INSERT INTO things VALUES ('B', 't2', 'e1', 'à Bob')");

        $alice = $this->json($this->call('GET', '/api/v1/_test/things', ['Authorization' => 'Bearer alice:t1', 'X-Establishment-Id' => 'e1']));
        $bob = $this->json($this->call('GET', '/api/v1/_test/things', ['Authorization' => 'Bearer bob:t2', 'X-Establishment-Id' => 'e1']));

        self::assertSame(['A'], array_column($alice['data'], 'id'));
        self::assertSame(['B'], array_column($bob['data'], 'id'));
    }

    public function testRouteSansEtablissementPlaceSeulementLeClientDansLeContexte(): void
    {
        $response = $this->call('GET', '/api/v1/_test/me', ['Authorization' => 'Bearer alice:t1']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['user' => 'alice', 'tenant' => 't1'], $this->json($response));
    }
}
