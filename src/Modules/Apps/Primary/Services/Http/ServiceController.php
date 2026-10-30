<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Services\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Services\Application\ServiceService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Cantine, garderie, transport : forfaits, abonnements, facturation par cycle. */
final class ServiceController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly ServiceService $services)
    {
        parent::__construct($responder, $access, $store);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->services->plans($principal, $establishmentId));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->string('service_kind', 20);
        $input->string('label');
        $input->string('fee_code', 40);
        $input->enum('billing_cycle', ServiceService::CYCLES);
        $input->int('capacity', 1, 5000, false, true);
        $input->string('route_label', 160, false, true);

        return $this->json($this->services->createPlan($principal, $establishmentId, $input->done()), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->string('label');
        $input->int('capacity', 1, 5000, false, true);
        $input->string('route_label', 160, false, true);
        $input->bool('active', false);

        return $this->json($this->services->updatePlan($principal, $establishmentId, self::id($request), $input->done()));
    }

    public function subscriptions(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(['items' => $this->services->subscriptions($principal, $establishmentId, self::id($request))]);
    }

    public function subscribe(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('person_id');
        $input->reference('academic_year_id');
        $input->date('starts_on');
        $v = $input->done();

        return $this->json($this->services->subscribe($principal, $establishmentId, self::id($request), (string) $v['person_id'], (string) $v['academic_year_id'], (string) $v['starts_on']), 201);
    }

    public function end(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->date('ends_on');
        $v = $input->done();

        return $this->json($this->services->end($principal, $establishmentId, self::id($request), (string) $v['ends_on']));
    }

    public function invoice(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->code('cycle_key', 40);
        $input->date('due_on');
        $v = $input->done();

        return $this->json($this->services->invoice($principal, $establishmentId, self::id($request), (string) $v['cycle_key'], (string) $v['due_on']));
    }
}
