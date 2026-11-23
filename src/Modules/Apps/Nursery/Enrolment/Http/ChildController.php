<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Enrolment\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Socle\People\Contracts\FoodAllergies;

/**
 * Enfants de l'école : liste paginée et fiche, bornées au périmètre de sections. Les allergies (donnée sensible, RG-MAT-3) ne figurent
 * que dans la fiche d'une personne qui détient `nursery.allergy.read` sur la section, et chaque consultation est journalisée.
 */
final class ChildController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, private readonly ChildDirectory $children, private readonly SectionService $sections, private readonly FoodAllergies $allergies)
    {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.child.read']);
        $query = $request->getQueryParams();
        $section = isset($query['section_id']) && is_string($query['section_id']) && $query['section_id'] !== '' ? $query['section_id'] : null;
        if ($section !== null) {
            $this->sections->get($perimeter, $section);
        }

        return $this->page($this->children->page($perimeter, Paging::fromRequest($request), $section));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.child.read']);
        $child = $this->children->get($perimeter, self::id($request));
        $allergyScope = $this->access->perimeter($principal, $establishmentId, ['nursery.allergy.read']);
        if ($allergyScope->coversSection((string) $child['section_id'])) {
            $child['allergies'] = $this->allergies->forLearners($principal->tenantId, [(string) $child['id']])[(string) $child['id']] ?? null;
            $this->access->audit($principal, 'nursery.allergy.viewed', 'child', (string) $child['id']);
        }

        return $this->json($child);
    }
}
