<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Attendance\Application\AttendanceAccess;
use Scholaris\Modules\Socle\Attendance\Application\JustificationService;
use Scholaris\Modules\Socle\Attendance\Http\Requests\DecisionRequest;
use Scholaris\Modules\Socle\Attendance\Http\Requests\JustificationRequest;
use Scholaris\Modules\Socle\Attendance\Http\Resources\Resource;
use Scholaris\Modules\Socle\Attendance\Infrastructure\JustificationRepository;

/** Justificatifs d'absence : dépôt (vie scolaire, apprenant ou garant), validation ou refus par la vie scolaire. */
final class JustificationController extends AttendanceController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AttendanceAccess $access,
        private readonly JustificationService $service,
        private readonly JustificationRepository $repository,
    ) {
        parent::__construct($responder, $context, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $query = ListQuery::fromRequest($request, ['status', 'person_id']);
        if (!$this->access->can($principal, $this->context->establishmentId(), AttendanceAccess::READ, AttendanceAccess::JUSTIFY)) {
            $learner = $query->filters['person_id'] ?? null;
            if ($learner === null || !$this->access->isSelfOrGuardian($principal, $learner)) {
                throw HttpException::forbidden('Précisez filter[person_id] : vous ne pouvez consulter que les justificatifs de vos enfants.');
            }
        }
        [$rows, $next] = $this->repository->page($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::justification());
    }

    /** Export CSV des justificatifs (mêmes filtres et mêmes droits que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $canReadAll = $this->access->can($principal, $this->context->establishmentId(), AttendanceAccess::READ, AttendanceAccess::JUSTIFY);
        $rows = $this->collectAll($request, ['status', 'person_id'], function (ListQuery $query) use ($principal, $canReadAll): array {
            if (!$canReadAll) {
                $learner = $query->filters['person_id'] ?? null;
                if ($learner === null || !$this->access->isSelfOrGuardian($principal, $learner)) {
                    throw HttpException::forbidden('Précisez filter[person_id] : vous ne pouvez consulter que les justificatifs de vos enfants.');
                }
            }

            return $this->repository->page($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);
        });
        $resource = Resource::justification();

        return $this->csv(
            'justificatifs.csv',
            ['Identifiant', 'Personne', 'Absence du', 'Absence au', 'Motif', 'Pièce', 'Statut', 'Déposé par', 'Décidé par', 'Décidé le', 'Absences justifiées', 'Créé le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['person_id'], $r['absence_from'], $r['absence_to'], $r['reason'], $r['document_ref'], $r['status'], $r['submitted_by'], $r['decided_by'], $r['decided_at'], $r['absences_justified'], $r['created_at']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $justification = $this->service->mustFind($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));
        if (!$this->access->can($principal, $this->context->establishmentId(), AttendanceAccess::READ, AttendanceAccess::JUSTIFY)
            && !$this->access->isSelfOrGuardian($principal, (string) $justification['person_id'])) {
            throw HttpException::notFound('Justificatif introuvable.');
        }

        return $this->responder->json(['data' => Resource::justification()->toArray($justification)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $data = (new JustificationRequest())->fromHttp($request);

        return $this->responder->json(['data' => Resource::justification()->toArray($this->service->submit($principal, $this->context->establishmentId(), $data))], 201);
    }

    public function accept(ServerRequestInterface $request): ResponseInterface
    {
        return $this->decide($request, true);
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        return $this->decide($request, false);
    }

    private function decide(ServerRequestInterface $request, bool $accept): ResponseInterface
    {
        $principal = $this->authorize($request, AttendanceAccess::JUSTIFY);
        $data = trim((string) $request->getBody()) === '' ? [] : (new DecisionRequest())->fromHttp($request);
        $note = isset($data['note']) ? (string) $data['note'] : null;

        return $this->responder->json(['data' => Resource::justification()->toArray($this->service->decide($principal, $this->context->establishmentId(), $this->param($request, 'id'), $accept, $note))]);
    }
}
