<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\BatchRegistrationService;

/** Inscription groupée depuis un registre papier (EX-ELV-07, T631) : directeur ou agent de saisie de l'inspection (R20). */
final class BatchController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PupilAccess $access,
        private readonly BatchRegistrationService $service,
    ) {
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $perimeter = $this->access->register($principal, $establishmentId, null);
        $result = $this->service->register($principal, $establishmentId, ApiRequest::body($request), !$perimeter->wholeSchool);

        return $this->responder->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = ApiRequest::principal($request);
        $establishmentId = ApiRequest::establishment($request);
        $perimeter = $this->access->register($principal, $establishmentId, null);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }
        $query = ListQuery::fromRequest($request);
        $rows = $this->service->list($principal->tenantId, $establishmentId, ListQuery::decodeCursor($query->cursor), $query->perPage + 1);
        $next = null;
        if (count($rows) > $query->perPage) {
            array_pop($rows);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return $this->responder->json(['data' => array_map(static fn (array $row): array => BatchRegistrationService::resource($row), $rows), 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]]);
    }
}
