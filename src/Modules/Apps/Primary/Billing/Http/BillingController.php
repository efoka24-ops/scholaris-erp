<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Billing\Application\EnrolmentInvoicing;
use Scholaris\Modules\Apps\Primary\Billing\Domain\PrimaryFees;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Frais du primaire : natures propres et facture d'inscription (EX-PRI-34). */
final class BillingController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly EnrolmentInvoicing $invoicing)
    {
        parent::__construct($responder, $access, $store);
    }

    public function feeKinds(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, ['primary.enrol', 'primary.settings.manage', 'primary.referential.read']);

        return $this->json(['items' => PrimaryFees::KINDS, 'base' => ['registration', 'tuition', 'other']]);
    }

    public function invoice(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->invoicing->issue($principal, $establishmentId, self::id($request)), 201);
    }
}
