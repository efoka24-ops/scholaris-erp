<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\ObservationService;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScopeResolver;

final class ObservationController
{
    private const WRITE = ['public_nursery.dev.observe', 'public_nursery.dev.manage'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentGuard $guard,
        private readonly SectionScopeResolver $scopes,
        private readonly ObservationService $observations,
    ) {
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, ...self::WRITE);
        $body = $this->jsonBody($request);
        $structureId = $body['structure_id'] ?? null;
        if (is_string($structureId) && trim($structureId) !== '') {
            $this->guard->assertSection($principal, $this->scopes->resolve($principal, self::WRITE, self::establishment($request)), trim($structureId));
        }
        $row = $this->observations->record($principal->tenantId, self::establishment($request), $body);

        return $this->responder->json(['data' => $row], 201);
    }

    private function authorize(ServerRequestInterface $request, string ...$anyOf): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishment = self::establishment($request);
        $this->guard->assertNursery($principal, $principal->tenantId, $establishment);
        foreach ($anyOf as $permission) {
            if ($this->permissions->can($principal, $permission, $establishment)) {
                return $principal;
            }
        }

        throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $anyOf) . '.');
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
}
