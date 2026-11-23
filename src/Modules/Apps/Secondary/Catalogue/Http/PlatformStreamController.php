<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Catalogue\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Catalogue\Application\StreamCatalogue;
use Scholaris\Modules\Apps\Secondary\Catalogue\Infrastructure\PdoStreamCatalogue;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Liste de référence tenue par la PLATEFORME (/platform/secondary/streams) : lecture, ajout (source obligatoire), renommage,
 * retrait ou remise, confirmation. L'accès (administrateur de plateforme) est contrôlé par PlatformAction avant cet appel.
 */
final class PlatformStreamController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly StreamCatalogue $catalogue,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = ListQuery::fromRequest($request, PdoStreamCatalogue::FILTERS, PdoStreamCatalogue::SORTS);
        $offset = StreamController::offset($query);
        $rows = $this->catalogue->platformPage($query->filters, $query->sorts, $query->perPage + 1, $offset);

        return $this->responder->json(StreamController::paginate($rows, $query, $offset));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->catalogue->platformShow(self::id($request))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = self::principal($request);
        $values = StreamInput::creation($request);
        $stream = $this->catalogue->addForPlatform($values, $principal->userId);
        $this->audit->append($principal->tenantId, $principal->userId, 'secondary.stream_ref.created', 'secondary_stream_ref', (string) $stream['id'], null, ['code' => $stream['code'], 'cycle_code' => $stream['cycle_code']]);

        return $this->responder->json(['data' => $stream], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = self::principal($request);
        $id = self::id($request);
        $values = StreamInput::change($request, true);
        $stream = $this->catalogue->changeForPlatform($id, $values, $principal->userId);
        $this->audit->append($principal->tenantId, $principal->userId, 'secondary.stream_ref.changed', 'secondary_stream_ref', $id, null, ['label_fr' => $stream['label_fr'], 'hidden' => $stream['hidden']]);

        return $this->responder->json(['data' => $stream]);
    }

    private static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw new \LogicException('PlatformAction doit précéder ce contrôleur.');
        }

        return $principal;
    }

    private static function id(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('id');

        return is_string($value) ? $value : '';
    }
}
