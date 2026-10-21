<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Apps\PublicSchools\Schools\Application\InfrastructureSnapshotService;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\Requests\RegistryInput;
use Scholaris\Modules\Apps\PublicSchools\Schools\Http\Resources\RegistryResource;
use Scholaris\Modules\Apps\PublicSchools\Schools\Policies\RegistryPolicy;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

final class RegistryController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly TenantContext $tenant,
        private readonly InfrastructureSnapshotService $registry,
        private readonly RegistryPolicy $policy,
        private readonly AuditLog $audit,
        private readonly TerritoryDirectory $territories,
    ) {
    }

    public function profile(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $row = $this->registry->profile($this->tenant->tenantId(), $this->tenant->establishmentId());
        if ($row === null) {
            throw HttpException::notFound('Profil école introuvable.');
        }

        return $this->responder->json(['data' => RegistryResource::profile($row)]);
    }

    public function territoryLineage(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $at = (new \DateTimeImmutable('now'))->format(DATE_ATOM);
        $lineage = $this->territories->lineage($this->tenant->tenantId(), (string) $request->getAttribute('id'), $at);
        if ($lineage === []) {
            throw HttpException::notFound('Territoire introuvable.');
        }

        return $this->responder->json(['data' => array_map(static fn ($info): array => [
            'id' => $info->id,
            'type' => $info->type,
            'code' => $info->code,
            'label_fr' => $info->labelFr,
            'label_en' => $info->labelEn,
            'parent_id' => $info->parentId,
            'version' => $info->version,
        ], $lineage)]);
    }

    public function saveProfile(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorizeManage($request);
        $input = RegistryInput::profile($request);
        $row = $this->registry->saveProfile($this->tenant->tenantId(), $this->tenant->establishmentId(), $input['name'], $input['school_code'], $input['territory_id']);
        $this->audit($request, $principal, 'public_primary.registry.profile_saved', 'school_profile', (string) $row['id'], RegistryResource::profile($row));

        return $this->responder->json(['data' => RegistryResource::profile($row)]);
    }

    public function latestInfrastructure(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $year = $request->getQueryParams()['school_year'] ?? null;
        if (!is_string($year) || preg_match('/^\d{4}-\d{4}$/', $year) !== 1) {
            throw HttpException::validation(['school_year' => ['Année scolaire attendue au format YYYY-YYYY.']]);
        }
        $row = $this->registry->latestSnapshot($this->tenant->tenantId(), $this->tenant->establishmentId(), $year);

        return $row === null
            ? $this->responder->json(['data' => null])
            : $this->responder->json(['data' => RegistryResource::snapshot($row)]);
    }

    public function showInfrastructure(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $row = $this->registry->snapshot($this->tenant->tenantId(), $this->tenant->establishmentId(), (string) $request->getAttribute('id'));
        if ($row === null) {
            throw HttpException::notFound('Snapshot introuvable.');
        }

        return $this->responder->json(['data' => RegistryResource::snapshot($row)]);
    }

    public function createInfrastructure(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorizeManage($request);
        $row = $this->registry->createSnapshot($this->tenant->tenantId(), $this->tenant->establishmentId(), RegistryInput::snapshot($request));
        $this->audit($request, $principal, 'public_primary.registry.infrastructure_created', 'infrastructure_snapshot', (string) $row['id'], RegistryResource::snapshot($row));

        return $this->responder->json(['data' => RegistryResource::snapshot($row)], 201);
    }

    public function updateInfrastructure(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorizeManage($request);
        $id = (string) $request->getAttribute('id');
        $row = $this->registry->updateDraft($this->tenant->tenantId(), $this->tenant->establishmentId(), $id, RegistryInput::snapshot($request));
        $this->audit($request, $principal, 'public_primary.registry.infrastructure_updated', 'infrastructure_snapshot', $id, RegistryResource::snapshot($row));

        return $this->responder->json(['data' => RegistryResource::snapshot($row)]);
    }

    public function correctInfrastructure(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorizeManage($request);
        $input = RegistryInput::correction($request);
        $row = $this->registry->correctSnapshot(
            $this->tenant->tenantId(),
            $this->tenant->establishmentId(),
            (string) $request->getAttribute('id'),
            $input['data'],
            $input['reason'],
        );
        $this->audit($request, $principal, 'public_primary.registry.infrastructure_corrected', 'infrastructure_snapshot', (string) $row['id'], RegistryResource::snapshot($row));

        return $this->responder->json(['data' => RegistryResource::snapshot($row)], 201);
    }

    public function closeInfrastructure(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorizeManage($request);
        $id = (string) $request->getAttribute('id');
        $row = $this->registry->closeSnapshot($this->tenant->tenantId(), $this->tenant->establishmentId(), $id, $principal->userId);
        $this->audit($request, $principal, 'public_primary.registry.infrastructure_closed', 'infrastructure_snapshot', $id, RegistryResource::snapshot($row));

        return $this->responder->json(['data' => RegistryResource::snapshot($row)]);
    }

    private function authorizeRead(ServerRequestInterface $request): void
    {
        $this->policy->authorizeRead($this->principal($request), $this->tenant->establishmentId());
    }

    private function authorizeManage(ServerRequestInterface $request): Principal
    {
        $principal = $this->principal($request);
        $this->policy->authorizeManage($principal, $this->tenant->establishmentId());

        return $principal;
    }

    private function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $principal;
    }

    /** @param array<string, mixed> $after */
    private function audit(ServerRequestInterface $request, Principal $principal, string $action, string $type, string $id, array $after): void
    {
        $server = $request->getServerParams();
        $ip = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : null;
        $device = $request->getHeaderLine('User-Agent');
        $this->audit->append(
            $this->tenant->tenantId(),
            $principal->userId,
            $action,
            $type,
            $id,
            null,
            $after,
            $ip,
            $device === '' ? null : mb_substr($device, 0, 255),
        );
    }
}
