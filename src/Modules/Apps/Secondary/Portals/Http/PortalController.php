<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Portals\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Portals\Application\PortalService;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Portails élève et parent du secondaire (T834) : lecture par son propre périmètre, paiement mobile d'une facture d'un enfant. */
final class PortalController extends Controller
{
    /** Bornes techniques de saisie (pas une règle de gestion) : montant XAF entier positif. */
    private const MAX_AMOUNT = 100000000;

    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly PortalService $portals)
    {
        parent::__construct($responder, $access, $store);
    }

    public function student(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->portals->student($principal, $establishmentId));
    }

    public function family(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->portals->family($principal, $establishmentId));
    }

    public function pay(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->reference('invoice_id');
        $input->int('amount_xaf', 1, self::MAX_AMOUNT);
        $input->enum('channel', PortalService::CHANNELS);
        $input->custom('payer_phone', static function (mixed $value): string {
            $phone = is_string($value) ? preg_replace('/[\s.-]/', '', $value) : null;
            if (!is_string($phone) || preg_match('/^\+?\d{8,15}$/', $phone) !== 1) {
                throw new \InvalidArgumentException('Numéro de téléphone invalide.');
            }

            return $phone;
        });
        $input->string('idempotency_key', 64);
        $v = $input->done();

        return $this->json($this->portals->pay($principal, $establishmentId, self::id($request), $v), 201);
    }

    public function paymentStatus(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->portals->paymentStatus($principal, $establishmentId, self::id($request), self::id($request, 'paymentId')));
    }
}
