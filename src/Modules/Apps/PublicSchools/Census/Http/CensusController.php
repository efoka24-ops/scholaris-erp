<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Census\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Census\Application\CensusService;
use Scholaris\Modules\Socle\Integrations\Contracts\TabularExporter;

/** Questionnaire statistique (STA) : ouverture pré-remplie, saisie, transmission (API-11), renvoi, correction, DOC-23, export (Q3). */
final class CensusController
{
    public const EXPORT_TYPE = 'public_schools.census';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly CensusService $service,
        private readonly TabularExporter $exporter,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->service->reader($principal, $establishmentId);

        return $this->responder->json(RequestInput::page(
            $request,
            fn (?string $after, int $limit): array => $this->service->list($principal->tenantId, $establishmentId, $after, $limit),
            static fn (array $row): array => CensusService::resource($row),
        ));
    }

    public function pending(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);

        return $this->responder->json(['data' => $this->service->pending($principal, RequestInput::attribute($request, 'establishment_id'))]);
    }

    public function open(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);

        return $this->responder->json(['data' => $this->service->open($principal, RequestInput::attribute($request, 'establishment_id'), RequestInput::body($request))], 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->service->reader($principal, $establishmentId);

        return $this->responder->json(['data' => CensusService::resource($this->service->find($principal->tenantId, $establishmentId, RequestInput::attribute($request, 'id')))]);
    }

    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);

        return $this->responder->json(['data' => $this->service->edit($principal, RequestInput::attribute($request, 'establishment_id'), RequestInput::attribute($request, 'id'), RequestInput::body($request))]);
    }

    /** API-11 : transmission au niveau suivant. */
    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);

        return $this->responder->json(['data' => $this->service->submit($principal, RequestInput::attribute($request, 'establishment_id'), RequestInput::attribute($request, 'id'), RequestInput::body($request))]);
    }

    public function sendBack(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);

        return $this->responder->json(['data' => $this->service->sendBack($principal, RequestInput::attribute($request, 'establishment_id'), RequestInput::attribute($request, 'id'), self::reason($request))]);
    }

    public function correct(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);

        return $this->responder->json(['data' => $this->service->correct($principal, RequestInput::attribute($request, 'establishment_id'), RequestInput::attribute($request, 'id'), self::reason($request))], 201);
    }

    public function document(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $locale = RequestInput::body($request)['locale'] ?? 'fr';

        return $this->responder->json(['data' => $this->service->document($principal, RequestInput::attribute($request, 'establishment_id'), RequestInput::attribute($request, 'id'), is_string($locale) ? $locale : 'fr')], 201);
    }

    /** Export au gabarit par défaut (CSV ou XLSX), colonnes paramétrables `public_schools.census.export.columns` (Q3). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->service->reader($principal, $establishmentId);
        $format = RequestInput::query($request, 'format') ?? 'csv';
        $rows = $this->service->exportRows($principal->tenantId, $establishmentId, RequestInput::attribute($request, 'id'));
        $file = $this->exporter->render($principal->tenantId, $establishmentId, self::EXPORT_TYPE, $rows, $format);

        return new Response(200, ['Content-Type' => $file->contentType, 'Content-Disposition' => 'attachment; filename="' . $file->filename . '"', 'Cache-Control' => 'private, no-store'], Stream::create($file->content));
    }

    private static function reason(ServerRequestInterface $request): string
    {
        $reason = RequestInput::body($request)['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 500) {
            throw HttpException::validation(['reason' => ['Motif requis (500 caractères au plus).']]);
        }

        return trim($reason);
    }
}
