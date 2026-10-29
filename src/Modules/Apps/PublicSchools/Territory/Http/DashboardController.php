<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Territory\Application\DashboardService;

/** Tableau de bord territorial (API-12, DOC-28) : agrégats masqués, indicateurs dérivés, descente vers les territoires enfants. */
final class DashboardController
{
    public function __construct(private readonly JsonResponder $responder, private readonly DashboardService $service)
    {
    }

    public function entry(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => ['territories' => $this->service->entry(ApiRequest::principal($request), ApiRequest::establishment($request))]]);
    }

    /** API-12 : GET /public/dashboards/{territory} (sans établissement imposé, comme GET /dashboards/territories/{territory}). */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $date = $request->getQueryParams()['date'] ?? null;

        return $this->responder->json(['data' => $this->service->show(ApiRequest::principal($request), ApiRequest::attribute($request, 'territory'), is_string($date) ? $date : null)]);
    }

    public function children(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => ['territories' => $this->service->children(ApiRequest::principal($request), ApiRequest::attribute($request, 'territory'))]]);
    }

    public function document(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $locale = is_array($body) && ($body['locale'] ?? 'fr') === 'en' ? 'en' : 'fr';

        return $this->responder->json(['data' => $this->service->document(ApiRequest::principal($request), ApiRequest::establishment($request), ApiRequest::attribute($request, 'territory'), $locale)], 201);
    }
}
