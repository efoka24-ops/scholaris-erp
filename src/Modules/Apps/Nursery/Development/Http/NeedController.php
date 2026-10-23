<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Development\Application\NeedService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\Paging;

/** Besoins particuliers : signalés par l'éducatrice, orientés et clos par la directrice. */
final class NeedController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, private readonly NeedService $needs)
    {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.need.read', 'nursery.need.report']);
        $query = $request->getQueryParams();
        $pick = static fn (string $name): ?string => isset($query[$name]) && is_string($query[$name]) && $query[$name] !== '' ? $query[$name] : null;

        return $this->page($this->needs->page($principal, $perimeter, Paging::fromRequest($request), $pick('learner_id'), $pick('status')));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.need.report']);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->enum('need_area', NeedService::AREAS);
        $input->string('summary', 500);

        return $this->json($this->needs->report($principal, $perimeter, $input->done()), 201);
    }

    public function orient(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->director($principal, $establishmentId);
        $input = Input::of($request);
        $input->string('orientation_note', 500);

        return $this->json($this->needs->orient($principal, $perimeter, self::id($request), (string) $input->done()['orientation_note']));
    }

    public function close(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->needs->close($principal, $this->director($principal, $establishmentId), self::id($request)));
    }

    private function director(\Scholaris\Core\Security\Principal $principal, string $establishmentId): \Scholaris\Modules\Apps\Nursery\Support\Perimeter
    {
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.need.read']);
        $this->access->assertWhole($principal, $perimeter, 'need_report');

        return $perimeter;
    }
}
