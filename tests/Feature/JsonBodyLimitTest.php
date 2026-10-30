<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AppTestCase;

/** OWASP API4 : un corps JSON démesuré est refusé (413) avant d'atteindre un contrôleur. */
final class JsonBodyLimitTest extends AppTestCase
{
    protected function setUp(): void
    {
        $this->boot([], ['api' => ['max_json_body_bytes' => 1024]]);
    }

    public function testUnCorpsJsonTropGrosEstRefuse(): void
    {
        $body = (string) json_encode(['x' => str_repeat('a', 2000)]);
        $response = $this->call('POST', '/api/v1/auth/login', ['Content-Type' => 'application/json'], $body);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('application/problem+json', explode(';', $response->getHeaderLine('Content-Type'))[0]);
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testLesReponsesDErreurPortentLesEnTetesDeSecuriteEtCors(): void
    {
        foreach (['/api/v1/route-inexistante', '/api/v1/_test/things'] as $path) {
            $response = $this->call('GET', $path, ['Origin' => 'https://app.scholaris.test']);

            self::assertGreaterThanOrEqual(400, $response->getStatusCode(), $path);
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), $path);
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'), $path);
            self::assertStringContainsString("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'), $path);
            self::assertSame('https://app.scholaris.test', $response->getHeaderLine('Access-Control-Allow-Origin'), $path);
        }
    }

    public function testUnContentLengthDeclareTropGrandEstRefuse(): void
    {
        $response = $this->call('POST', '/api/v1/auth/login', ['Content-Type' => 'application/json', 'Content-Length' => '999999'], '{}');

        self::assertSame(413, $response->getStatusCode());
    }

    public function testUnCorpsJsonRaisonnableEtUnImportCsvPassent(): void
    {
        $small = $this->call('POST', '/api/v1/auth/login', ['Content-Type' => 'application/json'], '{"login":"x","password":"y"}');
        $csv = $this->call('POST', '/api/v1/auth/login', ['Content-Type' => 'text/csv'], str_repeat('a;b' . "\n", 1000));

        self::assertNotSame(413, $small->getStatusCode());
        self::assertNotSame(413, $csv->getStatusCode());
    }
}
