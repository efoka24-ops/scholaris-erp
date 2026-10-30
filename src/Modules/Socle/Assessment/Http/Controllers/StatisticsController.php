<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Assessment\Application\SheetService;
use Scholaris\Modules\Socle\Assessment\Http\AssessmentPolicy;
use Scholaris\Modules\Socle\Assessment\Http\RequestContext;

/** Statistiques de séquence : moyenne, taux de réussite, minimum et maximum. */
final class StatisticsController extends AssessmentController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AssessmentPolicy $policy,
        private readonly SheetService $sheets,
    ) {
        parent::__construct($responder, $context, $policy);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $stats = $this->sheets->statistics(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            (string) $this->query($request, 'structure_id', true),
            (string) $this->query($request, 'period_id', true),
            $this->query($request, 'subject_id'),
        );

        return $this->responder->json(['data' => $stats]);
    }
}
