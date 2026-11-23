<?php

declare(strict_types=1);

namespace Tests\Support\Exports;

use Psr\Http\Message\ResponseInterface;

/** Appel brut d'une route d'export (le corps CSV n'est pas du JSON) depuis un test d'API. */
trait FetchesCsv
{
    /** @param array<string, string> $headers */
    protected function fetch(string $path, array $headers): ResponseInterface
    {
        return $this->call('GET', '/api/v1' . $path, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return list<list<string>> lignes décodées (BOM retiré, séparateur « ; »)
     */
    protected function csvRows(string $path, array $headers): array
    {
        $response = $this->fetch($path, $headers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        self::assertStringStartsWith("\xEF\xBB\xBF", $body);
        self::assertStringStartsWith('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        $rows = [];
        foreach (explode("\n", trim(substr($body, 3))) as $line) {
            $rows[] = str_getcsv($line, ';', '"', '');
        }

        return $rows;
    }

    /** @param array<string, string> $headers */
    protected function csvText(string $path, array $headers): string
    {
        return (string) $this->fetch($path, $headers)->getBody();
    }
}
