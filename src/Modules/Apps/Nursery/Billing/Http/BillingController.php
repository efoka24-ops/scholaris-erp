<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Billing\Application\BillingService;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;

/** Facture d'inscription et soldes (personnel : secrétaire, économe, directrice ; parent : solde de SES enfants). */
final class BillingController extends Controller
{
    private const STAFF = ['nursery.enrol', 'billing.manage', 'billing.read'];

    public function __construct(JsonResponder $responder, Access $access, private readonly BillingService $billing, private readonly ChildDirectory $children)
    {
        parent::__construct($responder, $access);
    }

    public function invoice(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.enrol', 'billing.manage']);

        return $this->json($this->billing->invoiceEnrolment($principal, $perimeter, self::id($request)), 201);
    }

    public function balance(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::STAFF);
        $this->children->assertInPerimeter($perimeter, self::id($request));

        return $this->json($this->billing->balance($principal->tenantId, $establishmentId, self::id($request)));
    }

    public function familyBalance(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $learnerId = $request->getQueryParams()['learner_id'] ?? null;
        if (!is_string($learnerId) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learnerId) !== 1) {
            throw HttpException::validation(['learner_id' => ['Identifiant de l’enfant requis.']]);
        }
        $this->access->ownChild($principal, $establishmentId, $learnerId);

        return $this->json($this->billing->balance($principal->tenantId, $establishmentId, $learnerId));
    }
}
