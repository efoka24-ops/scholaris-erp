<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AppTestCase;

final class ProblemDetailsTest extends AppTestCase
{
    protected function setUp(): void
    {
        $this->boot();
    }

    public function testValidationRenvoie422AvecErreursDeChamp(): void
    {
        $response = $this->call('GET', '/api/v1/_test/invalid');
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('https://api.scholaris.cm/errors/validation', $body['type']);
        self::assertSame('La note doit être comprise entre 0 et 20.', $body['detail']);
        self::assertSame(['La valeur 21 dépasse le maximum de 20.'], $body['errors']['grades.3.value']);
        self::assertSame($body['trace_id'], $response->getHeaderLine('X-Trace-Id'));
    }

    public function testErreurInterneNeRevelePasLesDetailsEnProduction(): void
    {
        $response = $this->call('GET', '/api/v1/_test/boom');
        $body = $this->json($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('mot de passe', (string) $response->getBody());
        self::assertSame('Une erreur interne est survenue.', $body['detail']);
        self::assertNotEmpty($body['trace_id']);
    }

    public function testAccesAuxDonneesSansContexteClientEstRefuseEn403(): void
    {
        $response = $this->call('GET', '/api/v1/_test/orphan');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('tenant', (string) $response->getBody());
    }

    public function testAccesNonAuthentifieRenvoie401(): void
    {
        $response = $this->call('GET', '/api/v1/_test/things');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }
}
