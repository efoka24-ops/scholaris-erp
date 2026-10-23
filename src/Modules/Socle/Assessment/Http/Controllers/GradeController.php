<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Assessment\Application\GradeService;
use Scholaris\Modules\Socle\Assessment\Application\VersionConflict;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;
use Scholaris\Modules\Socle\Assessment\Http\Requests\AssessmentRequest;
use Scholaris\Modules\Socle\Assessment\Http\Resources\Resource;
use Scholaris\Modules\Socle\Assessment\Infrastructure\GradeRepository;

/** Saisie des notes : cellule, lot, synchronisation hors ligne, import CSV, historique. */
final class GradeController extends AssessmentController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AssessmentPolicy $policy,
        private readonly GradeService $service,
        private readonly GradeRepository $grades,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $query = ListQuery::fromRequest($request, ['assessment_id', 'person_id']);
        [$rows, $next] = $this->grades->gradePage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::grade());
    }

    /** Export CSV des notes (mêmes filtres et même droit de lecture que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $rows = $this->collectAll(
            $request,
            ['assessment_id', 'person_id'],
            fn (ListQuery $query): array => $this->grades->gradePage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query),
        );
        $resource = Resource::grade();

        return $this->csv(
            'notes.csv',
            ['Identifiant', 'Évaluation', 'Apprenant', 'Note', 'Absent', 'Appréciation', 'Remarque', 'Version', 'Saisie par', 'Modifiée le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['assessment_id'], $r['person_id'], $r['score'], $r['absence'], $r['qualitative'], $r['remark'], $r['version'], $r['entered_by'], $r['updated_at']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $grade = $this->grades->grade($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id')) ?? throw HttpException::notFound('Note introuvable.');

        return $this->responder->json(['data' => Resource::grade()->toArray($grade)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::ENTER, AssessmentPolicy::VALIDATE);
        $data = (new AssessmentRequest(AssessmentRequest::ENTRY))->fromHttp($request);

        try {
            $result = $this->service->enter($principal, $this->context->establishmentId(), $data);
        } catch (VersionConflict $conflict) {
            return $this->conflictResponse($conflict);
        }

        return $this->responder->json(['data' => Resource::grade()->toArray($result['grade']), 'meta' => ['result' => $result['status']]], $result['status'] === 'created' ? 201 : 200);
    }

    public function batch(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::ENTER, AssessmentPolicy::VALIDATE);
        $data = (new AssessmentRequest(AssessmentRequest::BATCH))->fromHttp($request);

        try {
            $counts = $this->service->batch($principal, $this->context->establishmentId(), (string) $data['assessment_id'], $data['entries']);
        } catch (VersionConflict $conflict) {
            return $this->conflictResponse($conflict);
        }

        return $this->responder->json(['data' => $counts]);
    }

    /** Saisie hors ligne : 409 avec les deux versions si une note a changé entre-temps ; les autres notes sont enregistrées. */
    public function sync(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::ENTER, AssessmentPolicy::VALIDATE);
        $data = (new AssessmentRequest(AssessmentRequest::SYNC))->fromHttp($request);
        $results = $this->service->sync($principal, $this->context->establishmentId(), $data['entries']);

        $grade = Resource::grade();
        $out = [];
        $conflicts = 0;
        foreach ($results as $result) {
            $item = ['client_uuid' => $result['client_uuid'], 'status' => $result['status']];
            if (isset($result['grade'])) {
                $item['grade'] = $grade->toArray($result['grade']);
            }
            if ($result['status'] === 'conflict') {
                ++$conflicts;
                $item['server'] = $grade->toArray($result['server']);
                $item['client'] = $result['client'];
            }
            if (isset($result['message'])) {
                $item['message'] = $result['message'];
            }
            $out[] = $item;
        }

        if ($conflicts > 0) {
            return $this->responder->json([
                'type' => 'https://api.scholaris.cm/errors/conflict', 'title' => 'Conflit', 'status' => 409,
                'detail' => sprintf('%d note(s) ont été modifiées entre-temps : comparez les deux versions et choisissez. Les autres notes sont enregistrées.', $conflicts),
                'results' => $out,
            ], 409)->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
        }

        return $this->responder->json(['data' => ['results' => $out]]);
    }

    /** Import CSV : corps de la requête = fichier (text/csv), paramètre assessment_id. */
    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::ENTER, AssessmentPolicy::VALIDATE);
        $content = (string) $request->getBody();
        if (trim($content) === '') {
            throw HttpException::badRequest('Le fichier est vide : envoyez le CSV dans le corps de la requête.');
        }

        try {
            $counts = $this->service->import($principal, $this->context->establishmentId(), (string) $this->query($request, 'assessment_id', true), $content);
        } catch (VersionConflict $conflict) {
            return $this->conflictResponse($conflict);
        }

        return $this->responder->json(['data' => $counts]);
    }

    public function template(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::ENTER, AssessmentPolicy::VALIDATE);
        $csv = $this->service->template($this->context->tenantId(), $this->context->establishmentId(), (string) $this->query($request, 'assessment_id', true));

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="modele-notes.csv"')
            ->withBody($this->streams->createStream($csv));
    }

    public function events(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $events = $this->service->events($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));

        return $this->responder->json(['data' => Resource::event()->collection($events)]);
    }
}
