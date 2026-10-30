<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Portal\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\AuthorizedPersonsService;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

final class PortalPickupController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ActorDirectory $actors,
        private readonly PersonDirectory $people,
        private readonly EstablishmentGuard $guard,
        private readonly AuthorizedPersonsService $service,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $learnerId] = $this->authorizeLearner($request);

        return $this->responder->json(['data' => $this->service->list($principal->tenantId, self::establishment($request), $learnerId)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $learnerId] = $this->authorizeLearner($request, $this->jsonBody($request));
        $row = $this->service->create($principal->tenantId, self::establishment($request), $learnerId, $principal->userId, $this->jsonBody($request));

        return $this->responder->json(['data' => $row], 201);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $establishmentId = self::establishment($request);
        $this->guard->assertNursery($principal, $principal->tenantId, $establishmentId);
        $row = $this->service->find($principal->tenantId, $establishmentId, self::id($request)) ?? throw HttpException::notFound('Autorisation introuvable.');
        $this->assertLearnerOwned($principal->tenantId, $principal->userId, (string) $row['learner_id']);

        return $this->responder->json(['data' => $this->service->revoke($principal->tenantId, $establishmentId, self::id($request), $principal->userId)]);
    }

    /** @param array<string, mixed>|null $body
     * @return array{0: Principal, 1: string}
     */
    private function authorizeLearner(ServerRequestInterface $request, ?array $body = null): array
    {
        $principal = $this->principal($request);
        $tenantId = $principal->tenantId;
        $this->guard->assertNursery($principal, $tenantId, self::establishment($request));
        $source = $body ?? $request->getQueryParams();
        $learnerId = trim((string) ($source['learner_id'] ?? ''));
        if ($learnerId === '') {
            throw HttpException::validation(['learner_id' => ['Champ requis.']]);
        }
        $this->assertLearnerOwned($tenantId, $principal->userId, $learnerId);

        return [$principal, $learnerId];
    }

    private function assertLearnerOwned(string $tenantId, string $userId, string $learnerId): void
    {
        $ownerId = $this->actors->personIdOf($tenantId, $userId);
        if ($ownerId === null) {
            throw HttpException::forbidden('Compte parent non rattaché à une personne.');
        }
        $allowed = array_map(static fn (PersonSummary $person): string => $person->id, $this->people->learnersOf($tenantId, $ownerId));
        if (!in_array($learnerId, $allowed, true)) {
            throw HttpException::notFound('Cet enfant ne relève pas de votre portail famille.');
        }
    }

    private function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $principal;
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
