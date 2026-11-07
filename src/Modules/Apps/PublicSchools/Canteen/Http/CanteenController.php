<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Canteen\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Canteen\Application\CanteenService;

/** Cantine et santé scolaire (CAN) : repas du jour par classe, synthèse mensuelle, stocks, campagnes de santé en effectifs. */
final class CanteenController
{
    public function __construct(private readonly JsonResponder $responder, private readonly CanteenService $service)
    {
    }

    public function meals(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(RequestInput::pageOf($request, $this->service->meals(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::query($request, 'date'))));
    }

    public function saveMeals(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->saveMeals(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::attribute($request, 'date'), RequestInput::body($request))]);
    }

    public function summary(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->summary(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::query($request, 'month'))]);
    }

    public function moves(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(RequestInput::pageOf($request, $this->service->stockMoves(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'))));
    }

    public function addMove(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->addMove(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::body($request))], 201);
    }

    public function balance(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => ['items' => $this->service->balance(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'))]]);
    }

    public function campaigns(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(RequestInput::pageOf($request, $this->service->campaigns(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'))));
    }

    public function addCampaign(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->service->addCampaign(RequestInput::principal($request), RequestInput::attribute($request, 'establishment_id'), RequestInput::body($request))], 201);
    }
}
