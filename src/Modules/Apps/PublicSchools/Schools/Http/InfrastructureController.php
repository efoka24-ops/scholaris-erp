<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Schools\Application\InfrastructureYearService;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Infrastructures annuelles versionnées (EX-ECO-02, EX-ECO-03, EX-ECO-05) : version close immuable, correction = version n+1 motivée. */
final class InfrastructureController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly SchoolAccess $access,
        private readonly InfrastructureYearService $years,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->read($principal, 'public_schools.school.read', $establishmentId);
        $year = $request->getQueryParams()['year_label'] ?? null;
        if ($year !== null && (!is_string($year) || preg_match('/^\d{4}-\d{4}$/', $year) !== 1)) {
            throw HttpException::validation(['year_label' => ['Année scolaire attendue au format AAAA-AAAA.']]);
        }

        return $this->responder->json(['data' => $this->years->versions($principal->tenantId, $establishmentId, $year)]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->read($principal, 'public_schools.school.read', $establishmentId);
        $row = $this->years->find($principal->tenantId, $establishmentId, ApiRequest::attribute($request, 'id'))
            ?? throw HttpException::notFound('Version d\'infrastructure introuvable.');

        return $this->responder->json(['data' => $row]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->write($principal, 'public_schools.school.write', $establishmentId);
        $row = $this->years->create($principal->tenantId, $establishmentId, ApiRequest::body($request));
        $this->record($request, 'public_schools.infrastructure.created', $row);

        return $this->responder->json(['data' => $row], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->write($principal, 'public_schools.school.write', $establishmentId);
        $row = $this->years->updateDraft($principal->tenantId, $establishmentId, ApiRequest::attribute($request, 'id'), ApiRequest::body($request));
        $this->record($request, 'public_schools.infrastructure.updated', $row);

        return $this->responder->json(['data' => $row]);
    }

    public function close(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->write($principal, 'public_schools.school.write', $establishmentId);
        $row = $this->years->close($principal->tenantId, $establishmentId, ApiRequest::attribute($request, 'id'), $principal->userId);
        $this->record($request, 'public_schools.infrastructure.closed', $row);

        return $this->responder->json(['data' => $row]);
    }

    public function correct(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->write($principal, 'public_schools.school.write', $establishmentId);
        $body = ApiRequest::body($request);
        $reason = $body['reason'] ?? null;
        if (!is_string($reason)) {
            throw HttpException::validation(['reason' => ['Motif de correction requis.']]);
        }
        $data = $body['data'] ?? [];
        if (!is_array($data)) {
            throw HttpException::validation(['data' => ['Objet attendu.']]);
        }
        $row = $this->years->correct($principal->tenantId, $establishmentId, ApiRequest::attribute($request, 'id'), $data, $reason);
        $this->record($request, 'public_schools.infrastructure.corrected', $row);

        return $this->responder->json(['data' => $row], 201);
    }

    /** @param array<string, mixed> $row */
    private function record(ServerRequestInterface $request, string $action, array $row): void
    {
        $principal = ApiRequest::principal($request);
        $this->audit->append(
            $principal->tenantId,
            $principal->userId,
            $action,
            'infrastructure_year',
            (string) $row['id'],
            null,
            ['year_label' => $row['year_label'], 'version' => $row['version'], 'state' => $row['state'], 'correction_reason' => $row['correction_reason']],
            ApiRequest::ip($request),
            ApiRequest::device($request),
        );
    }
}
