<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamCentreService;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamListService;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamResultService;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;

/**
 * Examens côté délégation et direction des examens : listes d'un territoire, consolidation exportée (format Q10), taux de réussite par
 * école et par territoire, centres et sous-centres, intervenants (jurys) et état des vacations (décret 2010/1738).
 */
final class ExamTerritoryController
{
    public const VACATIONS_EXPORT = 'public_primary.exam_vacations';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ExamListService $lists,
        private readonly ExamResultService $results,
        private readonly ExamCentreService $centres,
        private readonly ExamController $exams,
    ) {
    }

    public function lists(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(Input::pageOf($request, $this->lists->territoryLists(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::attribute($request, 'territory'))));
    }

    public function results(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->results->territory(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::attribute($request, 'territory'))]);
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $rows = $this->lists->exportRows($principal, $establishmentId, Input::attribute($request, 'id'), Input::attribute($request, 'territory'));

        return $this->exams->file($principal->tenantId, $establishmentId, ExamController::LIST_EXPORT, $rows, Input::query($request, 'format') ?? 'csv');
    }

    public function vacations(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->centres->vacations(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::attribute($request, 'territory'))]);
    }

    public function vacationsExport(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $statement = $this->centres->vacations($principal, $establishmentId, Input::attribute($request, 'id'), Input::attribute($request, 'territory'));
        $rows = [];
        foreach ($statement['lines'] as $line) {
            $rows[] = ['kind' => 'official', 'centre_id' => $line['centre_id'], 'name' => $line['full_name'], 'function_code' => $line['function_code'], 'half_days' => $line['half_days'], 'copies_marked' => $line['copies_marked'], 'amount' => $line['amount']];
        }
        foreach ($statement['directors'] as $line) {
            $rows[] = ['kind' => 'director', 'centre_id' => null, 'name' => $line['establishment_id'], 'function_code' => 'school_director', 'half_days' => null, 'copies_marked' => $line['candidates'], 'amount' => $line['amount']];
        }

        return $this->exams->file($principal->tenantId, $establishmentId, self::VACATIONS_EXPORT, $rows, Input::query($request, 'format') ?? 'csv');
    }

    public function centres(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(Input::pageOf($request, $this->centres->centres(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))));
    }

    public function createCentre(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->centres->create(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))], 201);
    }

    public function assign(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->centres->assign(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::attribute($request, 'centre'), Input::body($request))]);
    }

    public function officials(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(Input::pageOf($request, $this->centres->officials(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))));
    }

    public function addOfficial(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->centres->addOfficial(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))], 201);
    }
}
