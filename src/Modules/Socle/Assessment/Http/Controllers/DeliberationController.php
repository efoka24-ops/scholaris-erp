<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Assessment\Application\AssessmentSettings;
use Scholaris\Modules\Socle\Assessment\Application\DeliberationService;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;
use Scholaris\Modules\Socle\Assessment\Http\Requests\AssessmentRequest;
use Scholaris\Modules\Socle\Assessment\Http\Resources\Resource;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;

/** Délibération en séance : présents et quorum, décisions du jury, clôture, signatures, réouverture. */
final class DeliberationController extends AssessmentController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AssessmentPolicy $policy,
        private readonly DeliberationService $service,
        private readonly ResultRepository $repo,
        private readonly AssessmentSettings $settings,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::READ, AssessmentPolicy::DELIBERATION);
        $query = ListQuery::fromRequest($request, ['structure_id', 'period_id', 'status', 'exam_session']);
        [$rows, $next] = $this->repo->deliberationPage($this->context->tenantId(), $this->context->establishmentId(), $query->filters, $query);

        return $this->pageResponse($request, $rows, $next, Resource::deliberation());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::READ, AssessmentPolicy::DELIBERATION);

        return $this->one($this->service->find($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id')));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::DELIBERATION);
        $data = (new AssessmentRequest(AssessmentRequest::DELIBERATION))->fromHttp($request);

        return $this->one($this->service->open($principal, $this->context->establishmentId(), $data), 201);
    }

    public function attendance(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::DELIBERATION);
        $data = (new AssessmentRequest(AssessmentRequest::ATTENDANCE))->fromHttp($request);

        return $this->one($this->service->attendance($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'), $data['members_present']));
    }

    public function start(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::DELIBERATION);

        return $this->one($this->service->start($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id')));
    }

    public function updateLine(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AssessmentPolicy::DELIBERATION);
        $data = (new AssessmentRequest(AssessmentRequest::DELIBERATION_LINE))->fromHttp($request);

        return $this->one($this->service->updateLine(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $this->param($request, 'id'),
            $this->param($request, 'line_id'),
            $data,
        ));
    }

    public function close(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::DELIBERATION);

        return $this->one($this->service->close($principal, $this->context->establishmentId(), $this->param($request, 'id')));
    }

    /** Signature du procès-verbal par un membre présent (le service vérifie sa présence). */
    public function sign(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, AssessmentPolicy::READ, AssessmentPolicy::DELIBERATION, AssessmentPolicy::DELIBERATION_SIGN);

        return $this->one($this->service->sign($principal, $this->context->establishmentId(), $this->param($request, 'id')));
    }

    /**
     * Réouverture exceptionnelle, motivée : droit assessment.deliberation.reopen_permission (défaut : qui approuve les
     * corrections ; IPES : direction académique).
     */
    public function reopen(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, $this->settings->reopenPermission($this->context->tenantId(), $this->context->establishmentId()));
        $data = (new AssessmentRequest(AssessmentRequest::REOPEN))->fromHttp($request);

        return $this->one($this->service->reopen($principal, $this->context->establishmentId(), $this->param($request, 'id'), (string) $data['reason']));
    }

    /** @param array<string, mixed> $row */
    private function one(array $row, int $status = 200): ResponseInterface
    {
        return $this->responder->json(['data' => Resource::deliberation()->with($row, ['lines' => Resource::deliberationLine()])], $status);
    }
}
