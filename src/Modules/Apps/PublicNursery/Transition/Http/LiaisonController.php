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
use Scholaris\Modules\Apps\PublicNursery\Transition\Application\LiaisonSheetService;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

final class LiaisonController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly EstablishmentGuard $guard,
        private readonly LiaisonSheetService $sheets,
        private readonly PersonDirectory $people,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $learnerId = trim((string) (($request->getQueryParams()['learner_id'] ?? null) ?? ''));
        $schoolYear = trim((string) (($request->getQueryParams()['school_year'] ?? null) ?? ''));
        if ($learnerId === '' || $schoolYear === '') {
            throw HttpException::validation(['learner_id' => ['learner_id et school_year sont requis.']]);
        }
        $sheet = $this->sheets->findByLearnerYear($principal->tenantId, self::establishment($request), $learnerId, $schoolYear)
            ?? throw HttpException::notFound('Fiche DOC-20 introuvable.');
        $person = $this->people->find($principal->tenantId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable.');

        return $this->responder->json(['data' => $sheet + ['html' => $this->sheets->html($sheet, $person->fullName())]]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $body = $this->jsonBody($request);
        $row = $this->sheets->saveDraft($principal->tenantId, self::establishment($request), trim((string) ($body['learner_id'] ?? '')), trim((string) ($body['school_year'] ?? '')));
        $person = $this->people->find($principal->tenantId, (string) $row['learner_id']) ?? throw HttpException::notFound('Enfant introuvable.');

        return $this->responder->json(['data' => $row + ['html' => $this->sheets->html($row, $person->fullName())]], 201);
    }

    public function sign(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request);
        $body = $this->jsonBody($request);
        $row = $this->sheets->sign($principal->tenantId, self::establishment($request), trim((string) ($body['learner_id'] ?? '')), trim((string) ($body['school_year'] ?? '')), $principal->userId);
        $person = $this->people->find($principal->tenantId, (string) $row['learner_id']) ?? throw HttpException::notFound('Enfant introuvable.');

        return $this->responder->json(['data' => $row + ['html' => $this->sheets->html($row, $person->fullName())]]);
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
}
