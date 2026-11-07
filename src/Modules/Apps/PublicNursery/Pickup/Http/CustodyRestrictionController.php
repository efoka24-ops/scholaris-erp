<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Pickup\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\CustodyRestrictionService;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;

final class CustodyRestrictionController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentGuard $guard,
        private readonly CustodyRestrictionService $service,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $query = $request->getQueryParams();
        $learnerId = trim((string) ($query['learner_id'] ?? ''));
        if ($learnerId === '') {
            throw HttpException::validation(['learner_id' => ['Champ requis.']]);
        }
        $rows = $this->service->list(
            $principal->tenantId,
            self::establishment($request),
            $learnerId,
            isset($query['on_date']) ? trim((string) $query['on_date']) : null,
            ($query['active_only'] ?? '0') === '1',
        );
        $this->service->recordRead($principal->tenantId, $principal->userId, $learnerId, count($rows));

        return $this->responder->json(['data' => $rows]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $body = $this->jsonBody($request);
        $learnerId = trim((string) ($body['learner_id'] ?? ''));
        if ($learnerId === '') {
            throw HttpException::validation(['learner_id' => ['Champ requis.']]);
        }
        $row = $this->service->record($principal->tenantId, self::establishment($request), $learnerId, $body, $principal->userId);

        return $this->responder->json(['data' => $row], 201);
    }

    public function lift(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $body = $this->jsonBody($request);
        $row = $this->service->lift($principal->tenantId, self::establishment($request), self::id($request), trim((string) ($body['reason'] ?? '')), $principal->userId);

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
        foreach (['public_nursery.pickup.handover', 'settings.manage'] as $permission) {
            if ($this->permissions->can($principal, $permission, $establishmentId)) {
                return $principal;
            }
        }

        throw HttpException::notFound('Cette route ne relève pas de votre périmètre.');
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
