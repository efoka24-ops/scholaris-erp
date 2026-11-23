<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Identity\Application\RoleAssignmentService;
use Scholaris\Modules\Socle\Identity\Http\Requests\AssignmentRequest;
use Scholaris\Modules\Socle\Identity\Http\Resources\IdentityResource;

/** Affectations de rôle : liste, création, suppression et délégation temporaire. */
final class RoleAssignmentController
{
    public function __construct(
        private readonly RoleAssignmentService $service,
        private readonly JsonResponder $responder,
        private readonly Problems $problems,
        private readonly Actor $actor,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = ListQuery::fromRequest($request, ['person_id', 'role', 'scope_type']);
        $filters = array_intersect_key($query->filters, array_flip(['person_id', 'role', 'scope_type']));
        $rows = $this->service->list(
            $this->actor->user($request),
            (string) $request->getAttribute('establishment_id'),
            $filters,
            ListQuery::decodeCursor($query->cursor),
            $query->perPage + 1,
        );
        $hasMore = count($rows) > $query->perPage;
        $rows = array_slice($rows, 0, $query->perPage);
        $next = $hasMore && $rows !== [] ? ListQuery::encodeCursor($rows[count($rows) - 1]->id) : null;

        return $this->responder->json([
            'data' => array_map(IdentityResource::assignment(...), $rows),
            'links' => ['next' => $next === null ? null : $request->getUri()->getPath() . '?cursor=' . $next . '&per_page=' . $query->perPage],
            'meta' => ['next_cursor' => $next],
        ]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user($request);
        $this->service->assertMayAssign($actor);
        $assignment = $this->service->assign($actor, AssignmentRequest::from($request));

        return $this->responder->json(['data' => IdentityResource::assignment($assignment)], 201);
    }

    public function delegate(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user($request);
        $this->service->assertMayDelegate($actor);
        $assignment = $this->service->delegate($actor, AssignmentRequest::from($request));

        return $this->responder->json(['data' => IdentityResource::assignment($assignment)], 201);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->service->revoke($this->actor->user($request), (string) $request->getAttribute('id'));

        return $this->problems->noContent();
    }
}
