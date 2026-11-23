<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Admissions\Application\AdmissionPermissions;
use Scholaris\Modules\Apps\Higher\Admissions\Application\AdmissionsAccess;
use Scholaris\Modules\Apps\Higher\Admissions\Application\ApplicationService;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\PublicAdmissions;

/** Espace du candidat connecté : campagnes ouvertes, ses candidatures avec leur suivi, le suivi d'une candidature. */
final class CandidateController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ApplicationService $applications,
        private readonly AdmissionsAccess $access,
        private readonly PublicAdmissions $public,
    ) {
    }

    public function openCampaigns(ServerRequestInterface $request): ResponseInterface
    {
        $principal = AdmissionsAccess::principal($request);
        $establishmentId = AdmissionsAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, [AdmissionPermissions::SUBMIT]);

        return $this->responder->json(['data' => $this->public->openCampaigns($principal->tenantId, $establishmentId)]);
    }

    public function mine(ServerRequestInterface $request): ResponseInterface
    {
        [$query, $offset] = AdmissionsResources::listQuery($request, []);
        $rows = $this->applications->mine(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), $query->perPage + 1, $offset);

        return $this->responder->json(AdmissionsResources::page($rows, $query->perPage, $offset, static fn (array $row): array => $row));
    }

    public function followUp(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');

        return $this->responder->json(['data' => $this->applications->followUp(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), is_string($id) ? $id : '')]);
    }
}
