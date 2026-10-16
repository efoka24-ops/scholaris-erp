<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Vacations\Application\VacationAccess;
use Scholaris\Modules\Apps\Higher\Vacations\Application\VacationDocuments;
use Scholaris\Modules\Apps\Higher\Vacations\Application\VacationPermissions;
use Scholaris\Modules\Apps\Higher\Vacations\Application\WorkflowService;

/** Visa de l'école, approbation de la direction, rejet, paiement (EX-ENS-07, NOT-14), DOC-25 et DOC-26. */
final class VacationWorkflowController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly VacationAccess $access,
        private readonly WorkflowService $workflow,
        private readonly VacationDocuments $documents,
    ) {
    }

    public function validateSchool(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(VacationResources::statement($this->workflow->validateSchool(VacationAccess::principal($request), VacationAccess::establishmentId($request), VacationController::arg($request))));
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(VacationResources::statement($this->workflow->approve(VacationAccess::principal($request), VacationAccess::establishmentId($request), VacationController::arg($request))));
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = VacationAccess::principal($request);
        $establishmentId = VacationAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, VacationPermissions::REJECT);
        $body = VacationBody::of($request);
        $reason = $body->string('reason', 500);
        $body->done(['reason']);

        return $this->responder->json(VacationResources::statement($this->workflow->reject($principal, $establishmentId, VacationController::arg($request), (string) $reason)));
    }

    public function pay(ServerRequestInterface $request): ResponseInterface
    {
        $principal = VacationAccess::principal($request);
        $establishmentId = VacationAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, VacationPermissions::PAY);
        $body = VacationBody::of($request);
        $method = $body->choice('payment_method', VacationPermissions::PAYMENT_METHODS);
        $reference = $body->string('payment_reference', 80);
        $paidOn = $body->string('paid_on', 10, false, '/^\d{4}-\d{2}-\d{2}$/');
        $body->done(['payment_method', 'payment_reference', 'paid_on']);

        return $this->responder->json(VacationResources::statement($this->workflow->pay($principal, $establishmentId, VacationController::arg($request), (string) $method, (string) $reference, $paidOn)));
    }

    public function statementDocument(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->documents->statement(VacationAccess::principal($request), VacationAccess::establishmentId($request), VacationController::arg($request)), 201);
    }

    public function payslip(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json($this->documents->payslip(VacationAccess::principal($request), VacationAccess::establishmentId($request), VacationController::arg($request)), 201);
    }
}
