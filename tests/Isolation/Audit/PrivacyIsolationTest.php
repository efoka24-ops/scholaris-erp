<?php

declare(strict_types=1);

namespace Tests\Isolation\Audit;

use Tests\Support\Audit\AuditTestCase;

/** Aucune fuite de conformité entre clients. */
final class PrivacyIsolationTest extends AuditTestCase
{
    private string $requestId;

    protected function setUp(): void
    {
        $this->bootAudit();
        $this->requestId = $this->newRequest('erasure')['id'];
    }

    public function testIdentifiantsDUnAutreClientDonnent404(): void
    {
        $id = $this->requestId;
        foreach ([
            ['GET', '/data-requests/' . $id],
            ['GET', '/data-requests/' . $id . '/data'],
            ['POST', '/data-requests/' . $id . '/start'],
            ['POST', '/data-requests/' . $id . '/refuse', ['reason' => 'x']],
            ['POST', '/data-requests/' . $id . '/approval', ['decision' => 'approve']],
            ['POST', '/data-requests/' . $id . '/complete'],
        ] as $call) {
            $code = $this->api($call[0], $call[1], 'bob', 't2', $call[2] ?? null)[0]->getStatusCode();
            self::assertSame(404, $code, $call[0] . ' ' . $call[1]);
        }
        self::assertSame('received', $this->api('GET', '/data-requests/' . $id, 'alice', 't1')[1]['data']['status']);
    }

    public function testListesEtExportNeContiennentQueLeClient(): void
    {
        self::assertSame([], $this->api('GET', '/data-requests', 'bob', 't2')[1]['data']);
        self::assertCount(1, $this->api('GET', '/data-requests', 'alice', 't1')[1]['data']);

        $this->newRequest('access', 'p9', 'bob', 't2');
        $bytes = (string) $this->api('POST', '/privacy/tenant-export', 'bob', 't2')[0]->getBody();
        self::assertStringNotContainsString($this->requestId, $this->extract($bytes));
    }

    private function extract(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'iso');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $zip->open($path);
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $all;
    }
}
