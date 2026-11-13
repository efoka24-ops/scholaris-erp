<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Messaging\Http\Resources\Resource;

/** Enveloppe de liste paginée par curseur, commune aux contrôleurs du module. */
final class Pages
{
    /** @param list<array<string, mixed>> $rows */
    public static function respond(JsonResponder $responder, ServerRequestInterface $request, Resource $resource, array $rows, ?string $next): ResponseInterface
    {
        $links = ['next' => null];
        if ($next !== null) {
            $params = $request->getQueryParams();
            $params['cursor'] = $next;
            $links['next'] = $request->getUri()->getPath() . '?' . http_build_query($params);
        }

        return $responder->json([
            'data' => $resource->collection($rows),
            'links' => $links,
            'meta' => ['next_cursor' => $next],
        ]);
    }
}
