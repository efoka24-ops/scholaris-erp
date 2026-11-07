<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Promotion\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicPrimary\Promotion\Application\PromotionService;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;

/** Passage de classe (PAS) : politique, propositions, conseil des maîtres (API-08), avis de l'inspecteur, recours, PV DOC-12, statistiques. */
final class PromotionController
{
    public function __construct(private readonly JsonResponder $responder, private readonly PromotionService $service)
    {
    }

    public function policy(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $this->service->reader($principal, $establishmentId, null);
        $policy = $this->service->policy($principal->tenantId, $establishmentId);

        return $this->responder->json(['data' => ['version' => $policy->version, 'na_for_remediation' => $policy->naForRemediation, 'na_for_council' => $policy->naForCouncil, 'lock_level' => 'national']]);
    }

    public function setPolicy(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $policy = $this->service->setPolicy($principal, Input::establishment($request), Input::body($request));

        return $this->responder->json(['data' => ['version' => $policy->version, 'na_for_remediation' => $policy->naForRemediation, 'na_for_council' => $policy->naForCouncil, 'lock_level' => 'national']]);
    }

    public function propose(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);

        return $this->responder->json(['data' => $this->service->propose($principal, Input::establishment($request), Input::attribute($request, 'year'), Input::body($request))], 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $structureId = Input::requiredQuery($request, 'structure_id', 'Classe requise.');
        $reach = $this->service->reader($principal, $establishmentId, $structureId);
        $rows = $this->service->list($principal->tenantId, $establishmentId, Input::attribute($request, 'year'), $structureId);
        if (!$reach->isInSchool()) {
            // Inspection : seulement les décisions qui attendent son avis (RG-PAS-2), sans le reste de la classe.
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['state'] === 'pending_opinion'));
        }

        return $this->responder->json(Input::pageOf($request, $rows));
    }

    public function council(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);

        return $this->responder->json(['data' => $this->service->council($principal, Input::establishment($request), Input::attribute($request, 'year'), Input::body($request))], 201);
    }

    public function opinion(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);

        return $this->responder->json(['data' => $this->service->opinion($principal, Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))]);
    }

    public function appeal(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);

        return $this->responder->json(['data' => $this->service->appeal($principal, Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))]);
    }

    public function minutes(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $locale = Input::body($request)['locale'] ?? 'fr';

        return $this->responder->json(['data' => $this->service->minutes($principal, Input::establishment($request), Input::attribute($request, 'id'), is_string($locale) ? $locale : 'fr')], 201);
    }

    public function statistics(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $structureId = Input::query($request, 'structure_id');
        $this->service->reader($principal, $establishmentId, $structureId);

        return $this->responder->json(['data' => $this->service->statistics($principal->tenantId, $establishmentId, Input::attribute($request, 'year'), $structureId)]);
    }
}
