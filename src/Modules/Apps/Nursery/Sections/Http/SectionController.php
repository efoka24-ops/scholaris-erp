<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Sections\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\Paging;

/** Sections de l'école et enfants par section (EX-MAT-04). Lecture : périmètre de sections ; écriture : directrice, tout l'établissement. */
final class SectionController extends Controller
{
    private const READ = ['nursery.child.read'];
    private const MANAGE = ['nursery.section.manage'];

    public function __construct(JsonResponder $responder, Access $access, private readonly SectionService $sections, private readonly ChildDirectory $children)
    {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);

        return $this->page(Paging::fromRequest($request)->slice($this->sections->inPerimeter($perimeter)));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'section');
        $input = Input::of($request);
        $input->string('label', 160);
        $input->int('capacity', 1, 500, false);
        $input->int('sort_order', 0, 99, false);
        $input->enum('subsystem', SectionService::SUBSYSTEMS, false);
        $input->reference('structure_id', false);

        return $this->json($this->sections->create($principal, $establishmentId, $input->done()), 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'section');
        $input = Input::of($request, true);
        $input->string('label', 160, false);
        $input->int('capacity', 1, 500, false, true);
        $input->int('sort_order', 0, 99, false);
        $input->enum('subsystem', SectionService::SUBSYSTEMS, false);

        return $this->json($this->sections->update($principal, $establishmentId, self::id($request), $input->done()));
    }

    public function children(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $section = $this->sections->get($perimeter, self::id($request));

        return $this->page($this->children->page($perimeter, Paging::fromRequest($request), (string) $section['id']));
    }
}
