<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;

final class SchoolController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly TenantContext $tenant,
        private readonly PermissionChecker $permissions,
        private readonly SchoolRegistry $schools,
    ) {
    }

    public function current(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishmentId = $this->tenant->establishmentId();
        if (!$this->permissions->can($principal, 'public_schools.school.read', $establishmentId)) {
            throw HttpException::notFound();
        }
        $school = $this->schools->find($this->tenant->tenantId(), $establishmentId);
        if ($school === null) {
            throw HttpException::notFound('Fiche école introuvable.');
        }

        return $this->responder->json(['data' => [
            'establishment_id' => $school->establishmentId,
            'official_code' => $school->officialCode,
            'subsystem' => $school->subsystem,
            'cycle' => $school->cycle,
            'school_group_ref' => $school->schoolGroupRef,
            'status' => $school->status,
        ]]);
    }
}
