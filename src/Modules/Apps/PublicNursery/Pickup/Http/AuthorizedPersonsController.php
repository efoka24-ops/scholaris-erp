<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Pickup\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\AuthorizedPersonsService;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;

final class AuthorizedPersonsController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentGuard $guard,
        private readonly AuthorizedPersonsService $service,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $learnerId = trim((string) (($request->getQueryParams()['learner_id'] ?? null) ?? ''));
        if ($learnerId === '') {
            throw HttpException::validation(['learner_id' => ['Champ requis.']]);
        }

        return $this->responder->json(['data' => $this->service->list($principal->tenantId, self::establishment($request), $learnerId)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $body = $this->jsonBody($request);
        $learnerId = trim((string) ($body['learner_id'] ?? ''));
        if ($learnerId === '') {
            throw HttpException::validation(['learner_id' => ['Champ requis.']]);
        }
        $row = $this->service->create($principal->tenantId, self::establishment($request), $learnerId, $principal->userId, $body);

        return $this->responder->json(['data' => $row], 201);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $row = $this->service->revoke($principal->tenantId, self::establishment($request), self::id($request), $principal->userId);

        return $this->responder->json(['data' => $row]);
    }

    private function authorize(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishmentId = self::establishment($request);
        $this->guard->assertNursery($principal, $principal->tenantId, $establishmentId);
        foreach (['attendance.discipline.manage', 'settings.manage', 'public_nursery.pickup.handover'] as $permission) {
            if ($this->permissions->can($principal, $permission, $establishmentId)) {
                return $principal;
            }
        }

        throw HttpException::forbidden('Permission requise : attendance.discipline.manage, public_nursery.pickup.handover ou settings.manage.');
    }

    /** @return array<string, mixed> */
    private function jsonBody(ServerRequestInterface $request): array
    {
        $raw = trim((string) $request->getBody());
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            throw HttpException::validation(['body' => ['JSON attendu.']]);
        }

        return $body;
    }

    private static function establishment(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('establishment_id');

        return is_string($id) && $id !== '' ? $id : throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
    }

    private static function id(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');

        return is_string($id) && $id !== '' ? $id : throw HttpException::badRequest('Identifiant manquant.');
    }
}
