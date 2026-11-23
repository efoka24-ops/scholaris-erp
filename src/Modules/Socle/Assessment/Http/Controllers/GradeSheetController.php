<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Assessment\Application\AssessmentService;
use Scholaris\Modules\Socle\Assessment\Application\SheetService;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;
use Scholaris\Modules\Socle\Assessment\Http\Requests\AssessmentRequest;
use Scholaris\Modules\Socle\Assessment\Http\Resources\Resource;

/** Grille de saisie d'une classe, fiche d'une épreuve et validation de la grille. */
final class GradeSheetController extends AssessmentController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AssessmentPolicy $policy,
        private readonly SheetService $sheets,
        private readonly AssessmentService $assessments,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    /** Une ligne par élève, une colonne par épreuve, moyenne de séquence et appréciation, compteurs d'erreurs et de notes manquantes. */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $grid = $this->sheets->grid(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            (string) $this->query($request, 'structure_id', true),
            (string) $this->query($request, 'period_id', true),
            $this->query($request, 'subject_id'),
        );

        return $this->responder->json(['data' => $grid]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $sheet = $this->sheets->sheet($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));
        $sheet['assessment'] = Resource::assessment()->toArray($sheet['assessment']);

        return $this->responder->json(['data' => $sheet]);
    }

    /** « Valider la grille » : fait passer toutes les épreuves de la classe à l'état demandé (droit et 2FA du cycle de l'application). */
    public function transition(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorizeRead($request);
        $data = (new AssessmentRequest(AssessmentRequest::SHEET_TRANSITION))->fromHttp($request);
        $rows = $this->assessments->transitionSheet(
            $principal,
            $this->context->establishmentId(),
            (string) $data['structure_id'],
            (string) $data['period_id'],
            isset($data['subject_id']) ? (string) $data['subject_id'] : null,
            (string) $data['to'],
        );

        return $this->responder->json(['data' => Resource::assessment()->collection($rows)]);
    }
}
