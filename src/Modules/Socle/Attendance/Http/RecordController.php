<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Attendance\Application\AttendanceAccess;
use Scholaris\Modules\Socle\Attendance\Http\Resources\Resource;
use Scholaris\Modules\Socle\Attendance\Infrastructure\AttendanceRepository;

/** Lignes de présence. Un parent (ou l'apprenant) n'y accède qu'avec filter[person_id] sur ses propres enfants. */
final class RecordController extends AttendanceController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AttendanceAccess $access,
        private readonly AttendanceRepository $repository,
    ) {
        parent::__construct($responder, $context, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $query = ListQuery::fromRequest($request, ['session_id', 'person_id', 'status', 'date', 'justified']);
        $this->requireDate($query->filters['date'] ?? null, 'filter[date]');
        if (!$this->access->can($principal, $this->context->establishmentId(), AttendanceAccess::READ, AttendanceAccess::TAKE)) {
            $learner = $query->filters['person_id'] ?? null;
            if ($learner === null || !$this->access->isSelfOrGuardian($principal, $learner)) {
                throw HttpException::forbidden('Précisez filter[person_id] : vous ne pouvez consulter que l\'assiduité de vos enfants.');
            }
        }
        [$rows, $next] = $this->repository->recordPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::record());
    }

    /** Export CSV des lignes de présence, dont les absences (filter[status]=A) ; mêmes filtres et mêmes droits que la liste. */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $canReadAll = $this->access->can($principal, $this->context->establishmentId(), AttendanceAccess::READ, AttendanceAccess::TAKE);
        $rows = $this->collectAll($request, ['session_id', 'person_id', 'status', 'date', 'justified'], function (ListQuery $query) use ($principal, $canReadAll): array {
            $this->requireDate($query->filters['date'] ?? null, 'filter[date]');
            if (!$canReadAll) {
                $learner = $query->filters['person_id'] ?? null;
                if ($learner === null || !$this->access->isSelfOrGuardian($principal, $learner)) {
                    throw HttpException::forbidden('Précisez filter[person_id] : vous ne pouvez consulter que l\'assiduité de vos enfants.');
                }
            }

            return $this->repository->recordPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);
        });
        $resource = Resource::record();

        return $this->csv(
            'presences.csv',
            ['Identifiant', 'Séance', 'Personne', 'Date', 'Statut', 'Arrivée', 'Justifiée', 'Justificatif'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['session_id'], $r['person_id'], $r['session_date'], $r['status'], $r['arrived_at'], $r['justified'], $r['justification_id']];
            }, $rows),
            $request,
        );
    }
}
