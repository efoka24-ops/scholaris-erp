<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AppTestCase;
use Tests\Support\TestController;

final class IdempotencyTest extends AppTestCase
{
    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        TestController::$payments = 0;
        $this->boot();
        $this->access->grant('alice', 'e1');
        $this->headers = ['Authorization' => 'Bearer alice:t1', 'X-Establishment-Id' => 'e1'];
    }

    public function testCleObligatoire(): void
    {
        $response = $this->call('POST', '/api/v1/_test/payments', $this->headers, '{"amount":1000}');

        self::assertSame(400, $response->getStatusCode());
    }

    public function testRejeuAvecLaMemeCleNeRefaitPasLOperation(): void
    {
        $headers = $this->headers + ['Idempotency-Key' => 'clef-0001-abcd'];
        $first = $this->call('POST', '/api/v1/_test/payments', $headers, '{"amount":1000}');
        $second = $this->call('POST', '/api/v1/_test/payments', $headers, '{"amount":1000}');

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(201, $second->getStatusCode());
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame('true', $second->getHeaderLine('Idempotent-Replayed'));
        self::assertSame(1, TestController::$payments, 'Une seule opération réelle.');
    }

    public function testMemeCleAvecUneRequeteDifferenteRenvoie422(): void
    {
        $headers = $this->headers + ['Idempotency-Key' => 'clef-0002-abcd'];
        $this->call('POST', '/api/v1/_test/payments', $headers, '{"amount":1000}');
        $response = $this->call('POST', '/api/v1/_test/payments', $headers, '{"amount":9999}');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(1, TestController::$payments);
    }

    public function testDesClientsDifferentsNePartagentPasLesCles(): void
    {
        $this->access->grant('bob', 'e9');
        $this->call('POST', '/api/v1/_test/payments', $this->headers + ['Idempotency-Key' => 'clef-0003-abcd'], '{"amount":1}');
        $other = $this->call('POST', '/api/v1/_test/payments', ['Authorization' => 'Bearer bob:t2', 'X-Establishment-Id' => 'e9', 'Idempotency-Key' => 'clef-0003-abcd'], '{"amount":1}');

        self::assertSame(201, $other->getStatusCode());
        self::assertSame(2, TestController::$payments);
    }
}
