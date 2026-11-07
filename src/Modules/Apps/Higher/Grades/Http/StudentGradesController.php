<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Academic\Http\Input;
use Scholaris\Modules\Apps\Higher\Grades\Application\QueryService;
use Scholaris\Modules\Apps\Higher\Grades\Application\StudentResultsService;

/** Espace de l'étudiant (R12) : ses résultats publiés (API-16, RG-NOT-4) et ses requêtes de note (EX-NOT-07). */
final class StudentGradesController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly StudentResultsService $results,
        private readonly QueryService $queries,
    ) {
    }

    public function results(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = GradesController::context($request);

        return $this->responder->json(['data' => $this->results->forStudent($principal, $establishmentId)]);
    }

    public function queries(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = GradesController::context($request);

        return $this->responder->json(['data' => $this->queries->visible($principal, $establishmentId, [], true)]);
    }

    public function createQuery(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = GradesController::context($request);
        $input = Input::of($request);
        $mark = $input->reference('mark_id');
        $message = $input->string('message', 1000);
        $input->done();

        return $this->responder->json(['data' => $this->queries->create($principal, $establishmentId, (string) $mark, (string) $message)], 201);
    }
}
