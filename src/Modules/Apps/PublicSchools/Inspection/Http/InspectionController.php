<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Inspection\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Inspection\Application\InspectionService;

/** Inspection (INS) : grilles, planning, visites et rapports (DOC-26), recommandations suivies, signalements de frais illégaux. */
final class InspectionController
{
    public function __construct(private readonly JsonResponder $responder, private readonly InspectionService $service)
    {
    }

    public function grids(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(RequestInput::pageOf($request, $this->service->grids(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'))));
    }

    public function scale(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->service->reader($principal, $establishmentId);

        return $this->responder->json(['data' => ['scale' => $this->service->scale($principal->tenantId, $establishmentId), 'status' => 'to_confirm']]);
    }

    public function saveGrid(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->saveGrid(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::body($request))], 201);
    }

    public function importExample(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->importExample(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'))], 201);
    }

    public function visits(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(RequestInput::pageOf($request, $this->service->visits(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'))));
    }

    public function plan(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->plan(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::body($request))], 201);
    }

    public function planning(ServerRequestInterface $request): ResponseInterface
    {
        $rows = $this->service->planning(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::query($request, 'from'), RequestInput::query($request, 'to'));

        return $this->responder->json(RequestInput::pageOf($request, $rows));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->show(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::attribute($request, 'id'))]);
    }

    public function report(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->report(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::attribute($request, 'id'), RequestInput::body($request))]);
    }

    public function document(ServerRequestInterface $request): ResponseInterface
    {
        $locale = (RequestInput::body($request)['locale'] ?? 'fr') === 'en' ? 'en' : 'fr';

        return $this->responder->json(['data' => $this->service->document(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::attribute($request, 'id'), $locale)], 201);
    }

    public function recommendations(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(RequestInput::pageOf($request, $this->service->recommendations(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::query($request, 'state'))));
    }

    public function feeReports(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(RequestInput::pageOf($request, $this->service->feeReports(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'))));
    }

    public function reportFee(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->reportFee(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::body($request))], 201);
    }

    public function handleFee(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->handleFee(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::attribute($request, 'id'), RequestInput::body($request))]);
    }
}
