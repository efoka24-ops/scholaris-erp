<?php

declare(strict_types=1);

namespace Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;

final class ListQueryTest extends TestCase
{
    /** @param array<string, mixed> $query */
    private function request(array $query): ServerRequest
    {
        return (new ServerRequest('GET', '/x'))->withQueryParams($query);
    }

    public function testValeursParDefaut(): void
    {
        $query = ListQuery::fromRequest($this->request([]));

        self::assertSame(50, $query->perPage);
        self::assertNull($query->cursor);
        self::assertSame([], $query->filters);
        self::assertSame([], $query->sorts);
    }

    public function testFiltresEtTrisAutorises(): void
    {
        $query = ListQuery::fromRequest(
            $this->request(['per_page' => '200', 'filter' => ['status' => 'open'], 'sort' => '-created_at,name']),
            ['status'],
            ['created_at', 'name'],
        );

        self::assertSame(200, $query->perPage);
        self::assertSame(['status' => 'open'], $query->filters);
        self::assertSame([['created_at', 'desc'], ['name', 'asc']], $query->sorts);
    }

    public function testPerPageAuDessusDuMaximumEstRefuse(): void
    {
        $this->expectException(HttpException::class);
        ListQuery::fromRequest($this->request(['per_page' => '201']));
    }

    public function testFiltreHorsListeBlancheEstRefuse(): void
    {
        $this->expectException(HttpException::class);
        ListQuery::fromRequest($this->request(['filter' => ['password' => 'x']]), ['status']);
    }

    public function testTriHorsListeBlancheEstRefuse(): void
    {
        $this->expectException(HttpException::class);
        ListQuery::fromRequest($this->request(['sort' => 'tenant_id']), [], ['name']);
    }

    public function testCurseurAllerRetour(): void
    {
        $cursor = ListQuery::encodeCursor('01HZZZ');

        self::assertSame('01HZZZ', ListQuery::decodeCursor($cursor));
        self::assertNull(ListQuery::decodeCursor(null));
    }
}
