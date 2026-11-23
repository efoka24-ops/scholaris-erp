<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\CandidateDocuments;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\CandidateService;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\ExportService;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\NationalExamsAccess as Access;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\ResultService;
use Scholaris\Modules\Apps\Higher\NationalExams\Application\SessionService;
use Scholaris\Modules\Apps\Higher\NationalExams\Domain\ResultRows;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamDocumentTypes;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamsStore;

/** Liste des candidats (EX-EXN-02 à 06) : ajout, constitution, dossier, retrait, export API-17, convocations, résultats, documents. */
final class CandidateController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly NationalExamsStore $store,
        private readonly SessionService $sessions,
        private readonly CandidateService $candidates,
        private readonly ExportService $exports,
        private readonly ResultService $results,
        private readonly CandidateDocuments $documents,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Access::principal($request);
        $establishmentId = Access::establishmentId($request);
        $id = NationalResources::arg($request, 'id');
        $this->sessions->readable($principal, $establishmentId, $id);

        return $this->responder->json(NationalResources::listing($request, $this->store, 'candidate', CandidateService::TABLE, $principal->tenantId, $establishmentId, ['status', 'group_id'], ['session_id' => $id], 'matricule, id', ['1 = 1', []]));
    }

    public function add(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $personId = (string) $body->string('person_id', 40);
        $body->done();

        return $this->responder->json(NationalResources::of('candidate', $this->candidates->add(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id'), $personId)), 201);
    }

    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        RequestBody::of($request)->done();

        return $this->responder->json($this->candidates->generate(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id')));
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $format = $body->choice('format', ExportService::FORMATS, false);
        $body->done();

        return $this->responder->json($this->exports->export(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id'), $format));
    }

    public function convene(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $centre = (string) $body->string('exam_centre', 120);
        $prefix = $body->string('number_prefix', 20, false) ?? '';
        $body->done();

        return $this->responder->json($this->exports->convene(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id'), $centre, $prefix));
    }

    public function importResults(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $csv = $body->string('csv', 2_000_000, false);
        $rows = $body->list('rows', false, 5000);
        if ($csv === null && $rows === null) {
            $body->error('rows', 'Lignes (rows) ou fichier CSV (csv) attendus.');
        }
        $body->done();
        $parsed = $csv !== null ? ResultRows::fromCsv($csv) : ResultRows::fromList($rows ?? []);

        return $this->responder->json($this->results->import(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id'), $parsed));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $changes = [];
        if ($body->has('pieces_received')) {
            $changes['pieces_received'] = $body->strings('pieces_received', true, 30) ?? [];
        }
        foreach (['fee_receipt' => 80, 'exam_centre' => 120, 'candidate_number' => 40] as $field => $max) {
            if ($body->has($field)) {
                $changes[$field] = $body->string($field, $max, false);
            }
        }
        if ($changes === []) {
            $body->error('pieces_received', 'Aucun champ à modifier.');
        }
        $body->done();

        return $this->responder->json(NationalResources::of('candidate', $this->candidates->update(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id'), $changes)));
    }

    public function withdraw(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $reason = (string) $body->string('reason', 300);
        $body->done();

        return $this->responder->json(NationalResources::of('candidate', $this->candidates->withdraw(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id'), $reason)));
    }

    public function document(ServerRequestInterface $request): ResponseInterface
    {
        $body = RequestBody::of($request);
        $code = (string) $body->choice('code', NationalExamDocumentTypes::CODES);
        $locale = $body->choice('locale', ['fr', 'en'], false);
        $body->done();

        return $this->responder->json($this->documents->issue(Access::principal($request), Access::establishmentId($request), NationalResources::arg($request, 'id'), $code, $locale), 201);
    }
}
