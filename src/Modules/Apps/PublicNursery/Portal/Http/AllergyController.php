<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Portal\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScopeResolver;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\FoodAllergies;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

final class AllergyController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly ActorDirectory $actors,
        private readonly PersonDirectory $people,
        private readonly EnrollmentDirectory $enrollments,
        private readonly FoodAllergies $allergies,
        private readonly EstablishmentGuard $guard,
        private readonly SectionScopeResolver $scopes,
    ) {
    }

    public function declare(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $tenantId = $principal->tenantId;
        $establishmentId = self::establishment($request);
        $this->guard->assertNursery($principal, $tenantId, $establishmentId);
        $body = $this->jsonBody($request);
        $learnerId = trim((string) ($body['learner_id'] ?? ''));
        if ($learnerId === '') {
            throw HttpException::validation(['learner_id' => ['Champ requis.']]);
        }
        $ownerId = $this->actors->personIdOf($tenantId, $principal->userId);
        if ($ownerId === null) {
            throw HttpException::forbidden('Compte parent non rattaché à une personne.');
        }
        $allowed = array_map(static fn (PersonSummary $person): string => $person->id, $this->people->learnersOf($tenantId, $ownerId));
        if (!in_array($learnerId, $allowed, true)) {
            throw HttpException::notFound('Cet enfant ne relève pas de votre portail famille.');
        }

        $this->allergies->declare($tenantId, $learnerId, $this->normalizeText($body['allergies'] ?? null), $principal->userId);

        return $this->responder->json(['data' => ['learner_id' => $learnerId, 'allergies' => $this->allergies->forLearners($tenantId, [$learnerId])[$learnerId] ?? null]]);
    }

    public function section(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $tenantId = $principal->tenantId;
        $establishmentId = self::establishment($request);
        $this->guard->assertNursery($principal, $tenantId, $establishmentId);
        if (!$this->permissions->can($principal, 'people.health.read', $establishmentId)) {
            throw HttpException::forbidden('Permission requise : people.health.read.');
        }
        $sectionId = trim((string) (($request->getQueryParams()['section_id'] ?? null) ?? ''));
        if ($sectionId === '') {
            throw HttpException::validation(['section_id' => ['Champ requis.']]);
        }
        $this->guard->assertSection($principal, $this->scopes->resolve($principal, ['people.health.read'], $establishmentId), $sectionId);

        $learnerIds = $this->enrollments->personIdsInStructure($tenantId, $sectionId);
        $allergies = $this->allergies->forLearners($tenantId, $learnerIds);
        $people = [];
        foreach ($this->people->findMany($tenantId, $learnerIds) as $person) {
            $people[$person->id] = $person;
        }

        $rows = [];
        foreach ($learnerIds as $learnerId) {
            $person = $people[$learnerId] ?? null;
            if ($person === null) {
                continue;
            }
            $rows[] = [
                'learner_id' => $learnerId,
                'learner_name' => $person->fullName(),
                'allergies' => $allergies[$learnerId] ?? null,
            ];
        }

        return $this->responder->json(['data' => $rows]);
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

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw HttpException::validation(['allergies' => ['Texte attendu.']]);
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }

    private static function establishment(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('establishment_id');

        return is_string($id) && $id !== '' ? $id : throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
    }
}
