<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\EvaluationService;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\RemediationService;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\ScoreService;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoEvaluation;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;

/**
 * Saisie des critères (API-06), grille, liste de remédiation (DOC-11), registre et fiche de suivi de remédiation, notes chiffrées
 * optionnelles. Lecture et écriture : enseignant titulaire de la classe ou directeur ; hors périmètre : 404 (lecture), 403 (écriture).
 */
final class EvaluationController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly EvaluationService $service,
        private readonly RemediationService $remediation,
        private readonly ScoreService $scores,
        private readonly PdoEvaluation $repository,
        private readonly ClassRoster $classes,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** PUT /units/{id}/criterion-checks (API-06). */
    public function saveChecks(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $result = $this->service->saveChecks($principal, Input::establishment($request), Input::attribute($request, 'id'), Input::body($request));

        return $this->responder->json(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function grid(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $structureId = Input::requiredQuery($request, 'structure_id', 'Classe requise.');
        $this->service->reader($principal, $establishmentId, $structureId);

        return $this->responder->json(['data' => $this->service->grid($principal->tenantId, $establishmentId, Input::attribute($request, 'id'), $structureId)]);
    }

    /** GET /units/{id}/remediation : liste de remédiation par discipline et critère (DOC-11). */
    public function remediationList(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $structureId = Input::requiredQuery($request, 'structure_id', 'Classe requise.');
        $this->service->reader($principal, $establishmentId, $structureId);

        return $this->responder->json(['data' => $this->service->remediationList($principal->tenantId, $establishmentId, Input::attribute($request, 'id'), $structureId)]);
    }

    public function saveScores(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);

        return $this->responder->json(['data' => $this->scores->save($principal, Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))]);
    }

    public function scores(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $structureId = Input::requiredQuery($request, 'structure_id', 'Classe requise.');
        $this->service->reader($principal, $establishmentId, $structureId);

        return $this->responder->json(['data' => $this->scores->list($principal->tenantId, $establishmentId, Input::attribute($request, 'id'), $structureId), 'meta' => [
            'enabled' => $this->service->numericScoresEnabled($principal->tenantId, $establishmentId),
        ]]);
    }

    public function sessions(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $structureId = Input::requiredQuery($request, 'structure_id', 'Classe requise.');
        $this->service->reader($principal, $establishmentId, $structureId);
        $from = Input::query($request, 'from');
        $to = Input::query($request, 'to');

        return $this->responder->json(Input::page(
            $request,
            fn (?string $after, int $limit): array => $this->repository->sessions($principal->tenantId, $establishmentId, $structureId, $from, $to, $after, $limit),
            static fn (array $row): array => RemediationService::sessionResource($row),
        ));
    }

    public function recordSession(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $body = Input::body($request);
        $structureId = is_string($body['structure_id'] ?? null) ? (string) $body['structure_id'] : '';
        $this->service->writer($principal, $establishmentId, $structureId, 'remediation_session');

        return $this->responder->json(['data' => $this->remediation->recordSession($principal, $establishmentId, $body)], 201);
    }

    public function weeklySummary(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $structureId = Input::requiredQuery($request, 'structure_id', 'Classe requise.');
        $this->service->reader($principal, $establishmentId, $structureId);
        $day = Input::query($request, 'date') ?? $this->clock->today();
        if (!Input::isDate($day)) {
            throw HttpException::validation(['date' => ['Date AAAA-MM-JJ attendue.']]);
        }

        return $this->responder->json(['data' => $this->remediation->weeklySummary($principal->tenantId, $establishmentId, $structureId, $day)]);
    }

    public function updateRemediation(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $id = Input::attribute($request, 'id');
        $row = $this->repository->remediation($principal->tenantId, $establishmentId, $id) ?? throw HttpException::notFound();
        $this->service->writer($principal, $establishmentId, (string) $row['structure_id'], $id);

        return $this->responder->json(['data' => $this->remediation->update($principal, $establishmentId, $row, Input::body($request))]);
    }

    /** GET /classes/{id}/pupils/{person}/remediation-sheet : fiche de suivi individualisée (obligatoire, arrêté du 14 août 2026). */
    public function sheet(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $structureId = Input::attribute($request, 'id');
        $personId = Input::attribute($request, 'person');
        $this->service->reader($principal, $establishmentId, $structureId);
        if (!in_array($personId, $this->classes->pupilIdsIn($principal->tenantId, $establishmentId, $structureId, $this->clock->today()), true)) {
            throw HttpException::notFound();
        }
        $name = $this->service->names($principal->tenantId, [$personId])[$personId] ?? '';

        return $this->responder->json(['data' => $this->remediation->sheet($principal->tenantId, $establishmentId, $personId, $name)]);
    }
}
