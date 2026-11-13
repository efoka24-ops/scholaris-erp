<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Pickup\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Pickup\Application\PickupService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Sortie sécurisée : écran de l'agent (classe du jour), remise, refus. */
final class PickupController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly PickupService $pickups)
    {
        parent::__construct($responder, $access, $store);
    }

    public function day(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->pickups->day($principal, $establishmentId, self::id($request)));
    }

    public function handover(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('person_id');
        $input->reference('pickup_id');
        $v = $input->done();

        return $this->json($this->pickups->handover($principal, $establishmentId, self::id($request), (string) $v['person_id'], (string) $v['pickup_id']), 201);
    }

    public function refuse(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('person_id');
        $input->string('presented_name');
        $input->string('note', 255, false, true);
        $v = $input->done();

        return $this->json($this->pickups->refuse($principal, $establishmentId, self::id($request), (string) $v['person_id'], (string) $v['presented_name'], isset($v['note']) ? (string) $v['note'] : null), 201);
    }
}
