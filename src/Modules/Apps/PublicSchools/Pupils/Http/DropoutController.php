<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\DropoutService;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Abandons scolaires déclarés par le directeur ou présumés par la tâche planifiée (EX-ELV-06) : suivi de la relance puis du résultat. */
final class DropoutController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PupilAccess $access,
        private readonly DropoutService $dropouts,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $read = $this->access->read($principal, $establishmentId);
        if ($read->isInSchool() && !$read->wholeSchool) {
            throw HttpException::notFound();
        }
        $this->access->named($principal, $read, 'dropout_list', $establishmentId, $request->getQueryParams()['purpose'] ?? null);
        $state = $request->getQueryParams()['state'] ?? 'open';

        return $this->responder->json(['data' => $this->dropouts->list($principal->tenantId, $establishmentId, $state !== 'all')]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $body = ApiRequest::body($request);
        $personId = $body['person_id'] ?? null;
        $note = $body['note'] ?? null;
        if (!is_string($personId) || $personId === '' || ($note !== null && !is_string($note))) {
            throw HttpException::validation(['person_id' => ['person_id requis ; note texte.']]);
        }
        $row = $this->dropouts->declareDropout($principal->tenantId, $establishmentId, $personId, $note, $principal->userId);
        $this->record($request, 'public_schools.dropout.declared', $row);

        return $this->responder->json(['data' => $row], 201);
    }

    public function contact(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $row = $this->dropouts->contact($principal->tenantId, $establishmentId, ApiRequest::attribute($request, 'id'));
        $this->record($request, 'public_schools.dropout.family_contacted', $row);

        return $this->responder->json(['data' => $row]);
    }

    public function resolve(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $this->access->manage($principal, 'public_schools.pupils.write', $establishmentId);
        $outcome = ApiRequest::body($request)['outcome'] ?? null;
        if (!is_string($outcome)) {
            throw HttpException::validation(['outcome' => ['Résultat requis.']]);
        }
        $row = $this->dropouts->resolve($principal->tenantId, $establishmentId, ApiRequest::attribute($request, 'id'), $outcome);
        $this->record($request, 'public_schools.dropout.resolved', $row);

        return $this->responder->json(['data' => $row]);
    }

    /** @param array<string, mixed> $row */
    private function record(ServerRequestInterface $request, string $action, array $row): void
    {
        $principal = ApiRequest::principal($request);
        $this->audit->append(
            $principal->tenantId,
            $principal->userId,
            $action,
            'dropout',
            (string) $row['id'],
            null,
            ['person_id' => $row['person_id'], 'kind' => $row['kind'], 'outcome' => $row['outcome']],
            ApiRequest::ip($request),
            ApiRequest::device($request),
        );
    }
}
