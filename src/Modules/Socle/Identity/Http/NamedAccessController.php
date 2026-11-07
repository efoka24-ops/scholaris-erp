<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Application\ScopedAccessAudit;

/** PP3 (T607) : le directeur voit qui a consulté des données nominatives de son établissement, et pourquoi. */
final class NamedAccessController
{
    public const PERMISSION = 'identity.named_access.read';

    public function __construct(
        private readonly ScopedAccessAudit $journal,
        private readonly PermissionChecker $permissions,
        private readonly JsonResponder $responder,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishmentId = (string) $request->getAttribute('establishment_id');
        if (!$this->permissions->can($principal, self::PERMISSION, $establishmentId)) {
            throw HttpException::forbidden();
        }
        $query = ListQuery::fromRequest($request);
        $rows = $this->journal->listFor($principal->tenantId, $establishmentId, ListQuery::decodeCursor($query->cursor), $query->perPage + 1);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor($rows[count($rows) - 1]['id']);
        }

        return $this->responder->json(['data' => $rows, 'meta' => ['next_cursor' => $next]]);
    }
}
