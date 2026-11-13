<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AppTestCase;

final class HttpConventionsTest extends AppTestCase
{
    protected function setUp(): void
    {
        $this->boot();
    }

    public function testLangueNegocieeEtRenvoyee(): void
    {
        self::assertSame('en', $this->call('GET', '/api/v1/health', ['Accept-Language' => 'en-GB,en;q=0.9'])->getHeaderLine('Content-Language'));
        self::assertSame('fr', $this->call('GET', '/api/v1/health')->getHeaderLine('Content-Language'));
    }

    public function testCorsAutoriseUneOrigineDeclareeEtRefusePourLesAutres(): void
    {
        $ok = $this->call('GET', '/api/v1/health', ['Origin' => 'https://app.scholaris.test']);
        $ko = $this->call('GET', '/api/v1/health', ['Origin' => 'https://pirate.example']);

        self::assertSame('https://app.scholaris.test', $ok->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $ko->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testPreflightRepond204SansPasserParLAuthentification(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/_test/payments', [
            'Origin' => 'https://app.scholaris.test',
            'Access-Control-Request-Method' => 'POST',
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString('Idempotency-Key', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }
}
