<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Assessment\Application\ResultAccess;
use Scholaris\Modules\Socle\Assessment\Application\ResultService;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;
use Scholaris\Modules\Socle\Assessment\Http\Requests\AssessmentRequest;
use Scholaris\Modules\Socle\Assessment\Http\Resources\Resource;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;

/**
 * Résultats : l'équipe pédagogique voit tout (calculés et publiés) ; une famille ne voit que les résultats PUBLIÉS
 * de ses enfants, sans proposition de calcul ni donnée interne.
 */
final class ResultController extends AssessmentController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AssessmentPolicy $policy,
        private readonly ResultService $service,
        private readonly ResultRepository $repo,
        private readonly ResultAccess $access,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $establishmentId = $this->context->establishmentId();
        $staff = $this->policy->canRead($principal, $establishmentId);
        $query = ListQuery::fromRequest($request, ['structure_id', 'period_id', 'person_id', 'status', 'exam_session']);
        $restrict = $staff ? null : $this->access->visiblePersons($principal);
        [$rows, $next] = $this->repo->resultPage($this->context->tenantId(), $establishmentId, $query->filters, $restrict, $query);

        return $this->pageResponse($request, $rows, $next, $staff ? Resource::result() : Resource::familyResult());
    }

    /** Export CSV des résultats : équipe pédagogique (tous) ou famille (résultats publiés de ses enfants), comme la liste. */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $establishmentId = $this->context->establishmentId();
        $staff = $this->policy->canRead($principal, $establishmentId);
        $restrict = $staff ? null : $this->access->visiblePersons($principal);
        $rows = $this->collectAll(
            $request,
            ['structure_id', 'period_id', 'person_id', 'status', 'exam_session'],
            fn (ListQuery $query): array => $this->repo->resultPage($this->context->tenantId(), $establishmentId, $query->filters, $restrict, $query),
        );
        $resource = $staff ? Resource::result() : Resource::familyResult();

        return $this->csv(
            'resultats.csv',
            ['Identifiant', 'Apprenant', 'Classe', 'Période', 'Moyenne', 'Rang', 'Effectif', 'Crédits acquis', 'Crédits totaux', 'Mention', 'Décision', 'Statut', 'Publié le'],
            array_map(static function (array $row) use ($resource, $staff): array {
                $r = $resource->toArray($row);

                return [
                    $r['id'], $r['person_id'], $r['structure_id'] ?? null, $r['period_label'] ?? $r['period_id'], $r['average'], $r['rank_in_class'], $r['class_size'],
                    $r['credits_earned'], $r['credits_total'], $r['mention'], $r['decision'], $staff ? $r['status'] : 'published', $r['published_at'],
                ];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $establishmentId = $this->context->establishmentId();
        $row = $this->repo->result($this->context->tenantId(), $establishmentId, $this->param($request, 'id')) ?? throw HttpException::notFound('Résultat introuvable.');

        if ($this->policy->canRead($principal, $establishmentId)) {
            return $this->responder->json(['data' => Resource::result()->toArray($row)]);
        }
        if ($row['status'] !== 'published' || !in_array((string) $row['person_id'], $this->access->visiblePersons($principal), true)) {
            throw HttpException::notFound('Résultat introuvable.');
        }

        return $this->responder->json(['data' => Resource::familyResult()->toArray($row)]);
    }

    /** Publication aux familles d'une classe pour une période. */
    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::PUBLISH);
        $data = (new AssessmentRequest(AssessmentRequest::PUBLISH))->fromHttp($request);
        $rows = $this->service->publish($principal, $this->context->establishmentId(), (string) $data['structure_id'], (string) $data['period_id'], $data['period_label'] ?? null, (string) ($data['exam_session'] ?? 'normal'));

        return $this->responder->json(['data' => Resource::result()->collection($rows)]);
    }
}
