<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Platform\Application\AccessRequestService;

/** Traitement des demandes d'accès par l'administrateur de plateforme : liste, détail, accepter, refuser, demander un complément. */
final class AccessRequestAdminController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly AccessRequestService $requests,
    ) {
    }

    /** GET /platform/access-requests?filter[status]=pending */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.requests.read');
        $query = ListQuery::fromRequest($request, ['status']);
        $status = $query->filters['status'] ?? null;
        if ($status !== null && !in_array($status, AccessRequestService::STATUSES, true)) {
            throw \Scholaris\Core\Http\HttpException::validation(['filter[status]' => ['Valeur attendue : ' . implode(', ', AccessRequestService::STATUSES) . '.']]);
        }
        [$rows, $next] = $this->requests->page($status, ListQuery::decodeCursor($query->cursor), $query->perPage);

        return $this->responder->json(['data' => $rows, 'meta' => ['next_cursor' => $next === null ? null : ListQuery::encodeCursor($next)]]);
    }

    /** GET /platform/access-requests/{id} */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.requests.read');

        return $this->responder->json(['data' => $this->requests->show(PlatformGuard::param($request, 'id'))]);
    }

    /** POST /platform/access-requests/{id}/accept { tenant_id? } */
    public function accept(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.requests.decide', true);
        $input = Input::of($request, ['tenant_id', 'force_reason']);
        $tenantId = $input->text('tenant_id', 1, 40, false, '/^[A-Za-z0-9_\-]+$/');
        // F2 : motif obligatoire pour forcer l'ouverture d'une application à venir ou retirée (tracé dans l'audit).
        $forceReason = $input->longText('force_reason', 1000);
        $input->validate();

        return $this->responder->json(['data' => $this->requests->accept(PlatformGuard::param($request, 'id'), $tenantId, $principal->userId, PlatformGuard::ip($request), $forceReason)]);
    }

    /** POST /platform/access-requests/{id}/reject { reason } (motif obligatoire, transmis au demandeur) */
    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.requests.decide', true);
        $input = Input::of($request, ['reason']);
        $reason = $input->longText('reason', 1000, true);
        $input->validate();

        return $this->responder->json(['data' => $this->requests->reject(PlatformGuard::param($request, 'id'), (string) $reason, $principal->userId, PlatformGuard::ip($request))]);
    }

    /** POST /platform/access-requests/{id}/request-info { message } */
    public function requestInfo(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.requests.decide');
        $input = Input::of($request, ['message']);
        $message = $input->longText('message', 1000, true);
        $input->validate();

        return $this->responder->json(['data' => $this->requests->requestInfo(PlatformGuard::param($request, 'id'), (string) $message, $principal->userId, PlatformGuard::ip($request))]);
    }
}
