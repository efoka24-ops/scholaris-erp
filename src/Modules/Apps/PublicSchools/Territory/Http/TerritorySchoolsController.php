<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Http;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/** Liste limitée aux écoles du territoire délégué, avec un retour minimal sans donnée d'élève. */
final class TerritorySchoolsController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ScopedByTerritory $scope,
        private readonly TerritoryDirectory $territories,
        private readonly SchoolRegistry $schools,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $id = $request->getAttribute('id');
        $establishment = $request->getAttribute('establishment_id');
        if (!is_string($id) || !is_string($establishment)) {
            throw HttpException::badRequest('Territoire ou établissement manquant.');
        }
        $at = (new DateTimeImmutable())->format(DATE_ATOM);
        $requested = $this->territories->find($principal->tenantId, $id, $at);
        if ($requested === null) {
            throw HttpException::notFound('Territoire introuvable.');
        }
        $perimeter = $this->scope->perimeter($principal, 'public_schools.school.read', $establishment);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }

        $allowed = [];
        if ($perimeter->isTerritorial()) {
            foreach ($perimeter->territoryIds as $scopeId) {
                foreach ($this->territories->descendantsOf($principal->tenantId, $scopeId, $at) as $node) {
                    $allowed[$node->id] = true;
                }
            }
        } else {
            $own = $this->territories->territoryOf($principal->tenantId, $establishment, $at);
            if ($own !== null) {
                foreach ($this->territories->lineage($principal->tenantId, $own->id, $at) as $node) {
                    $allowed[$node->id] = true;
                }
            }
        }
        if (!isset($allowed[$id])) {
            throw HttpException::notFound();
        }

        $establishments = $this->territories->establishmentsIn($principal->tenantId, $id, $at);
        if (!$perimeter->isTerritorial()) {
            $establishments = in_array($establishment, $establishments, true) ? [$establishment] : [];
        }
        $schools = [];
        foreach ($establishments as $schoolId) {
            $school = $this->schools->find($principal->tenantId, $schoolId);
            if ($school !== null) {
                $schools[] = [
                    'establishment_id' => $school->establishmentId,
                    'official_code' => $school->officialCode,
                    'subsystem' => $school->subsystem,
                    'cycle' => $school->cycle,
                    'status' => $school->status,
                ];
            }
        }

        return $this->responder->json(['data' => $schools]);
    }
}
