<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Academic\Application\TemplateService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Modèles de référentiel : liste, détail, application à une année (idempotente) et copie d'année (EX-PRI-05, 17). */
final class TemplateController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly TemplateService $templates)
    {
        parent::__construct($responder, $access, $store);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(['items' => array_map(static fn (array $r): array => Resources::template($r), $this->templates->all($principal, $establishmentId))]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(Resources::template($this->templates->find($principal, $establishmentId, self::id($request, 'code')), true));
    }

    public function apply(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('academic_year_id');
        $input->reference('section_org_unit_id', false, true);
        $values = $input->done();

        return $this->json($this->templates->apply($principal, $establishmentId, self::id($request, 'code'), (string) $values['academic_year_id'], isset($values['section_org_unit_id']) ? (string) $values['section_org_unit_id'] : null));
    }

    public function copyYear(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('from_year_id');
        $input->reference('to_year_id');
        $values = $input->done();

        return $this->json($this->templates->copyYear($principal, $establishmentId, (string) $values['from_year_id'], (string) $values['to_year_id']));
    }
}
