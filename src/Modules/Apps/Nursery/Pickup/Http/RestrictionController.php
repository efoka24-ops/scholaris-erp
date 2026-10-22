<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\RestrictionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\Paging;

/**
 * Restrictions de garde (RG-MAT-3) : saisies par la directrice seule. Quiconque n'a pas le droit de les lire (un parent, la
 * supervision) reçoit 404 : leur existence n'est jamais révélée.
 */
final class RestrictionController extends Controller
{
    private const READ = ['nursery.pickup.restrict', 'nursery.pickup.handover'];
    private const WRITE = ['nursery.pickup.restrict'];

    public function __construct(JsonResponder $responder, Access $access, private readonly RestrictionService $service)
    {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->assertNursery($principal, $establishmentId);
        $perimeter = $this->access->perimeter($principal, $establishmentId, self::READ);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }
        $query = $request->getQueryParams();
        $learner = isset($query['learner_id']) && is_string($query['learner_id']) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $query['learner_id']) === 1 ? $query['learner_id'] : null;

        return $this->page($this->service->page($principal, $perimeter, Paging::fromRequest($request), $learner));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->writer($principal, $establishmentId);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->string('restricted_name', 160);
        $input->reference('restricted_person_id', false);
        $input->reference('restricted_pickup_id', false);
        $input->enum('nature', RestrictionService::NATURES);
        $input->string('decision_reference', 120);
        $input->date('valid_from');
        $input->date('valid_to', false);

        return $this->json($this->service->create($principal, $perimeter, $input->done()), 201);
    }

    public function lift(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->writer($principal, $establishmentId);
        $input = Input::of($request);
        $input->string('reason', 500);

        return $this->json($this->service->lift($principal, $perimeter, self::id($request), (string) $input->done()['reason']));
    }

    private function writer(\Scholaris\Core\Security\Principal $principal, string $establishmentId): \Scholaris\Modules\Apps\Nursery\Support\Perimeter
    {
        $this->access->assertNursery($principal, $establishmentId);
        $perimeter = $this->access->perimeter($principal, $establishmentId, self::WRITE);
        if ($perimeter->isEmpty()) {
            // Sans le droit de saisir une décision de justice, la ressource n'existe pas pour l'appelant.
            throw HttpException::notFound();
        }
        $this->access->assertWhole($principal, $perimeter, 'custody_restriction');

        return $perimeter;
    }
}
