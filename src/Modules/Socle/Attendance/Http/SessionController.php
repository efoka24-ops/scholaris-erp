<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Attendance\Application\AttendanceAccess;
use Scholaris\Modules\Socle\Attendance\Application\SessionConflict;
use Scholaris\Modules\Socle\Attendance\Application\SessionService;
use Scholaris\Modules\Socle\Attendance\Http\Requests\RecordsRequest;
use Scholaris\Modules\Socle\Attendance\Http\Requests\ScanRequest;
use Scholaris\Modules\Socle\Attendance\Http\Requests\SessionRequest;
use Scholaris\Modules\Socle\Attendance\Http\Requests\SyncRequest;
use Scholaris\Modules\Socle\Attendance\Http\Resources\Resource;
use Scholaris\Modules\Socle\Attendance\Infrastructure\AttendanceRepository;

/** Appel par séance (écran Appel mobile) : création, lignes par lot, validation, synchronisation hors ligne, code de séance. */
final class SessionController extends AttendanceController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AttendanceAccess $access,
        private readonly SessionService $service,
        private readonly AttendanceRepository $repository,
    ) {
        parent::__construct($responder, $context, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::READ, AttendanceAccess::TAKE);
        $query = ListQuery::fromRequest($request, ['status', 'slot_id', 'structure_id', 'date', 'teacher_person_id']);
        $this->requireDate($query->filters['date'] ?? null, 'filter[date]');
        [$rows, $next] = $this->repository->sessionPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::session());
    }

    /** Export CSV des séances d'appel (mêmes filtres et mêmes droits que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::READ, AttendanceAccess::TAKE);
        $rows = $this->collectAll($request, ['status', 'slot_id', 'structure_id', 'date', 'teacher_person_id'], function (ListQuery $query): array {
            $this->requireDate($query->filters['date'] ?? null, 'filter[date]');

            return $this->repository->sessionPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);
        });
        $resource = Resource::session();

        return $this->csv(
            'seances-appel.csv',
            ['Identifiant', 'Date', 'Début', 'Fin', 'Classe', 'Matière', 'Enseignant', 'Statut', 'Effectif attendu', 'Appel pris par', 'Validé par', 'Validé le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['session_date'], $r['start_time'], $r['end_time'], $r['structure_id'], $r['subject_label'], $r['teacher_person_id'], $r['status'], $r['expected_count'], $r['taken_by'], $r['validated_by'], $r['validated_at']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::READ, AttendanceAccess::TAKE);

        return $this->responder->json(['data' => Resource::sessionDetail($this->service->find($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id')))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::TAKE);
        $data = (new SessionRequest())->fromHttp($request);
        try {
            [$session, $created] = $this->service->process($principal, $this->context->establishmentId(), $data);
        } catch (SessionConflict $e) {
            return $this->conflict($e);
        }

        return $this->responder->json(['data' => Resource::sessionDetail($session), 'meta' => ['replayed' => !$created]], $created ? 201 : 200);
    }

    public function saveRecords(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::TAKE);
        $data = (new RecordsRequest())->fromHttp($request);
        try {
            $session = $this->service->saveRecords($principal, $this->context->establishmentId(), $this->param($request, 'id'), $data['records']);
        } catch (SessionConflict $e) {
            return $this->conflict($e);
        }

        return $this->responder->json(['data' => Resource::sessionDetail($session)]);
    }

    public function validateCall(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::TAKE);

        return $this->responder->json(['data' => Resource::sessionDetail($this->service->validate($principal, $this->context->establishmentId(), $this->param($request, 'id')))]);
    }

    /** Synchronisation par lot : toujours 200, chaque appel porte son propre résultat (synced, conflict, error). */
    public function sync(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::TAKE);
        $data = (new SyncRequest())->fromHttp($request);
        $results = array_map(static function (array $result): array {
            if (isset($result['session'])) {
                $result['session'] = Resource::sessionDetail($result['session']);
            }

            return $result;
        }, $this->service->sync($principal, $this->context->establishmentId(), $data['sessions']));

        return $this->responder->json(['data' => $results]);
    }

    public function qrToken(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::TAKE);

        return $this->responder->json(['data' => $this->service->issueToken($principal, $this->context->establishmentId(), $this->param($request, 'id'))], 201);
    }

    /** Pointage de l'étudiant : aucune permission d'établissement, le service vérifie le jeton et l'inscription. */
    public function scan(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $data = (new ScanRequest())->fromHttp($request);
        $record = $this->service->scan($principal, $this->context->establishmentId(), $this->param($request, 'id'), (string) $data['token']);

        return $this->responder->json(['data' => Resource::record()->toArray($record)]);
    }
}
