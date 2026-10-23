<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Development\Application\FrameService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\Paging;

/** Référentiel d'observation : lecture pour le personnel, copie, adaptation et activation par la directrice. */
final class FrameController extends Controller
{
    private const READ = ['nursery.dev.read', 'nursery.dev.observe', 'nursery.frame.manage'];
    private const MANAGE = ['nursery.frame.manage'];

    public function __construct(JsonResponder $responder, Access $access, private readonly FrameService $frames)
    {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->page($this->frames->page($this->access->require($principal, $establishmentId, self::READ), Paging::fromRequest($request)));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->frames->get($this->access->require($principal, $establishmentId, self::READ), self::id($request)));
    }

    public function copy(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $perimeter] = $this->manager($request);

        return $this->json($this->frames->copy($principal, $perimeter, self::id($request)), 201);
    }

    public function addDomain(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $perimeter] = $this->manager($request);
        $input = Input::of($request);
        $input->code('code', 40);
        $input->string('label_fr', 160);
        $input->string('label_en', 160);
        $input->int('sort_order', 0, 99, false);

        return $this->json($this->frames->addDomain($principal, $perimeter, self::id($request), $input->done()), 201);
    }

    public function addItem(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $perimeter] = $this->manager($request);
        $input = Input::of($request);
        $input->reference('domain_id');
        $input->code('code', 40);
        $input->string('label_fr', 300);
        $input->string('label_en', 300);
        $input->int('sort_order', 0, 99, false);

        return $this->json($this->frames->addItem($principal, $perimeter, self::id($request), $input->done()), 201);
    }

    public function updateItem(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $perimeter] = $this->manager($request);
        $input = Input::of($request, true);
        $input->string('label_fr', 300, false);
        $input->string('label_en', 300, false);
        $input->int('sort_order', 0, 99, false);
        $input->bool('active', false);

        return $this->json($this->frames->updateItem($principal, $perimeter, self::id($request), self::id($request, 'item_id'), $input->done()));
    }

    public function activate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $perimeter] = $this->manager($request);
        $input = Input::of($request);
        $input->string('validation_ref', 120, false);
        $data = $input->done();

        return $this->json($this->frames->activate($principal, $perimeter, self::id($request), isset($data['validation_ref']) ? (string) $data['validation_ref'] : null));
    }

    /** @return array{0: \Scholaris\Core\Security\Principal, 1: \Scholaris\Modules\Apps\Nursery\Support\Perimeter} */
    private function manager(ServerRequestInterface $request): array
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'dev_frame');

        return [$principal, $perimeter];
    }
}
