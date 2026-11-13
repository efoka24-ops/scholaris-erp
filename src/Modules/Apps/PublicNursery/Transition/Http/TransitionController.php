<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Transition\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;
use Scholaris\Modules\Apps\PublicNursery\Transition\Application\NurseryPromotionPlanner;
use Scholaris\Modules\Apps\PublicNursery\Transition\Application\TransitionService;

final class TransitionController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentGuard $guard,
        private readonly TransitionService $transitions,
        private readonly NurseryPromotionPlanner $planner,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $schoolYear = trim((string) (($request->getQueryParams()['school_year'] ?? null) ?? ''));
        if ($schoolYear === '') {
            throw HttpException::validation(['school_year' => ['Champ requis.']]);
        }

        return $this->responder->json(['data' => $this->transitions->all($principal->tenantId, self::establishment($request), $schoolYear)]);
    }

    public function propose(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $body = $this->jsonBody($request);
        $fromYearId = trim((string) ($body['academic_year_id'] ?? ''));
        $toYearId = trim((string) ($body['next_year_id'] ?? ''));
        if ($fromYearId === '' || $toYearId === '') {
            throw HttpException::validation(['academic_year_id' => ['academic_year_id et next_year_id sont requis.']]);
        }

        $result = $this->planner->promote($principal->tenantId, self::establishment($request), $fromYearId, $toYearId);

        return $this->responder->json(['data' => ['status' => $result->status, 'summary' => $result->summary, 'counts' => $result->counts]]);
    }

    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $row = $this->transitions->decide($principal->tenantId, self::establishment($request), self::id($request), $principal->userId, $this->jsonBody($request));

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
        foreach (['public_nursery.transition.opinion', 'settings.manage'] as $permission) {
            if ($this->permissions->can($principal, $permission, $establishmentId)) {
                return $principal;
            }
        }

        throw HttpException::forbidden('Permission requise : public_nursery.transition.opinion ou settings.manage.');
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
