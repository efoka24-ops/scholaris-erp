<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Portal\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\AuthorizedPersonsService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\PickupCodeService;
use Scholaris\Modules\Apps\Nursery\Pickup\Http\AuthorizedPersonsController;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Socle\People\Contracts\FoodAllergies;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Portail du parent (EX-MAT-09, 20, 26) : un parent ne voit et ne modifie que SES enfants (garant d'un enfant, RG-MAT-4) ; tout autre
 * identifiant répond 404. Aucune permission `nursery.*` n'est portée par le parent : c'est le lien de garde qui ouvre ces routes.
 */
final class PortalController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        private readonly ChildDirectory $children,
        private readonly SectionService $sections,
        private readonly PersonDirectory $people,
        private readonly AuthorizedPersonsService $pickups,
        private readonly PickupCodeService $codes,
        private readonly FoodAllergies $allergies,
        private readonly NurseryClock $clock,
    ) {
        parent::__construct($responder, $access);
    }

    public function children(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $mine = $this->access->guardian($principal, $establishmentId);
        $rows = [];
        foreach ($this->people->findMany($principal->tenantId, $mine) as $child) {
            $section = $this->children->sectionOf($principal->tenantId, $establishmentId, $child->id);
            if ($section === null) {
                continue; // enfant inscrit dans un autre établissement du client
            }
            $rows[] = ['id' => $child->id, 'full_name' => $child->fullName(), 'section_id' => $section, 'section_label' => (string) $this->sections->findRaw($principal->tenantId, $establishmentId, $section)['label']];
        }

        return $this->page(Paging::fromRequest($request)->slice($rows));
    }

    public function pickups(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $learnerId] = $this->ownChildFromQuery($request);
        $query = $request->getQueryParams();
        $day = isset($query['date']) && is_string($query['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query['date']) === 1 ? $query['date'] : $this->clock->today();
        $perimeter = new Perimeter($principal->tenantId, $establishmentId, true);

        return $this->page($this->pickups->page($perimeter, Paging::fromRequest($request), $learnerId, $day));
    }

    public function addPickup(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('learner_id');
        AuthorizedPersonsController::fields($input);
        $data = $input->done();
        $this->access->ownChild($principal, $establishmentId, (string) $data['learner_id']);

        return $this->json($this->pickups->submitByParent($principal, $establishmentId, (string) $data['learner_id'], $data), 201);
    }

    public function revokePickup(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $mine = $this->access->guardian($principal, $establishmentId);
        $own = null;
        foreach ($mine as $learnerId) {
            if ($this->pickups->validFor($principal->tenantId, $establishmentId, $learnerId, $this->clock->today()) !== []) {
                foreach ($this->pickups->validFor($principal->tenantId, $establishmentId, $learnerId, $this->clock->today()) as $pickup) {
                    if ($pickup['id'] === self::id($request)) {
                        $own = $learnerId;
                    }
                }
            }
        }
        if ($own === null) {
            throw HttpException::notFound('Autorisation introuvable.');
        }

        return $this->json($this->pickups->revoke($principal, $establishmentId, self::id($request), null, $own));
    }

    public function issueCode(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->reference('pickup_id');
        $input->date('valid_on', false);
        $data = $input->done();
        $this->access->ownChild($principal, $establishmentId, (string) $data['learner_id']);
        $issued = $this->codes->issue($principal, $establishmentId, (string) $data['learner_id'], (string) $data['pickup_id'], (string) ($data['valid_on'] ?? $this->clock->today()));

        return $this->json($issued, 201)->withHeader('Cache-Control', 'no-store');
    }

    public function declareAllergy(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->string('allergies', 500, true, true);
        $data = $input->done();
        $learnerId = self::id($request);
        $this->access->ownChild($principal, $establishmentId, $learnerId);
        $text = isset($data['allergies']) ? trim((string) $data['allergies']) : null;
        $this->allergies->declare($principal->tenantId, $learnerId, $text === '' ? null : $text, $principal->userId);
        $this->access->audit($principal, 'nursery.allergy.declared', 'child', $learnerId);

        return $this->json(['child_id' => $learnerId, 'allergies' => $text === '' ? null : $text]);
    }

    /** @return array{0: \Scholaris\Core\Security\Principal, 1: string, 2: string} */
    private function ownChildFromQuery(ServerRequestInterface $request): array
    {
        [$principal, $establishmentId] = $this->context($request);
        $query = $request->getQueryParams();
        $learnerId = isset($query['learner_id']) && is_string($query['learner_id']) ? $query['learner_id'] : '';
        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learnerId) !== 1) {
            throw HttpException::validation(['learner_id' => ['Identifiant de l’enfant requis.']]);
        }
        $this->access->ownChild($principal, $establishmentId, $learnerId);

        return [$principal, $establishmentId, $learnerId];
    }
}
