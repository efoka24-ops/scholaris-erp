<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\BirthActAlertService;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamListService;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\ExamResultService;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\SecondaryTransferService;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Socle\Integrations\Contracts\TabularExporter;

/**
 * Examens côté école (EXA) : sessions, liste des candidats et circuit (API-09), DOC-13 à DOC-17, NOT-08, résultats de l'école,
 * attestations, transfert vers le secondaire.
 */
final class ExamController
{
    public const LIST_EXPORT = 'public_primary.exam_list';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ExamListService $lists,
        private readonly ExamResultService $results,
        private readonly BirthActAlertService $alerts,
        private readonly SecondaryTransferService $transfers,
        private readonly TabularExporter $exporter,
    ) {
    }

    public function sessions(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);

        return $this->responder->json(Input::pageOf($request, $this->lists->sessions($principal, Input::establishment($request), Input::query($request, 'year_label'))));
    }

    public function createSession(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->createSession(Input::principal($request), Input::establishment($request), Input::body($request))], 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->show(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))]);
    }

    public function candidates(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(Input::pageOf($request, $this->lists->candidates(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))));
    }

    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->generate(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))], 201);
    }

    public function add(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->add(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))], 201);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->submit(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))]);
    }

    /** API-09 : validation par l'étape en cours (inspection puis délégation). */
    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->validate(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))]);
    }

    public function sendBack(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->sendBack(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))]);
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $rows = $this->lists->exportRows($principal, $establishmentId, Input::attribute($request, 'id'), null);

        return $this->file($principal->tenantId, $establishmentId, self::LIST_EXPORT, $rows, Input::query($request, 'format') ?? 'csv');
    }

    public function document(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->document(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), self::locale($request))], 201);
    }

    public function birthActAlerts(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->alerts->forSchool(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))]);
    }

    /** API-10 : import des résultats officiels (territoire `territory_id`). */
    public function import(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->results->import(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))]);
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->results->publish(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))]);
    }

    public function results(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->results->school(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))]);
    }

    public function statement(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->results->statement(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), self::locale($request))], 201);
    }

    public function attestations(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->results->prepareAttestations(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), self::locale($request))], 201);
    }

    public function withdraw(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->withdraw(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))]);
    }

    public function validateCandidate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->validateCandidate(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))]);
    }

    public function sheet(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->lists->sheet(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), self::locale($request))], 201);
    }

    public function sign(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->results->sign(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))]);
    }

    public function createTransfer(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->transfers->create(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))], 201);
    }

    public function transfer(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->transfers->latest(Input::principal($request), Input::establishment($request), Input::attribute($request, 'id'))]);
    }

    /** @param list<array<string, scalar|null>> $rows */
    public function file(string $tenantId, string $establishmentId, string $type, array $rows, string $format): ResponseInterface
    {
        $file = $this->exporter->render($tenantId, $establishmentId, $type, $rows, $format);

        return new Response(200, ['Content-Type' => $file->contentType, 'Content-Disposition' => 'attachment; filename="' . $file->filename . '"', 'Cache-Control' => 'private, no-store'], Stream::create($file->content));
    }

    private static function locale(ServerRequestInterface $request): string
    {
        return (Input::body($request)['locale'] ?? 'fr') === 'en' ? 'en' : 'fr';
    }
}
