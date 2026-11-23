<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOrder;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOutcome;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester;
use Scholaris\Modules\Socle\Billing\Domain\PaymentChannel;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;

/** Implémentation du contrat d'encaissement (D4) : le paiement vise une facture et suit le chemin de POST /payments. */
final class PaymentRequesterService implements PaymentRequester
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentRepository $repository,
        private readonly InvoiceRepository $invoices,
    ) {
    }

    public function request(PaymentOrder $order): PaymentOutcome
    {
        $channel = PaymentChannel::tryFrom($order->channel)
            ?? throw HttpException::validation(['channel' => ['Canal inconnu. Valeurs possibles : ' . implode(', ', PaymentChannel::values()) . '.']]);
        if (preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $order->idempotencyKey) !== 1) {
            throw HttpException::validation(['idempotency_key' => ['Clé d\'idempotence de 8 à 100 caractères attendue.']]);
        }
        $invoice = $this->invoices->find($order->tenantId, $order->invoiceId);
        if ($invoice === null || $invoice['establishment_id'] !== $order->establishmentId) {
            throw HttpException::notFound('Facture introuvable.');
        }

        $result = $this->payments->record(new RecordPayment(
            $order->tenantId,
            $order->establishmentId,
            $order->actorId,
            $channel,
            $order->amountXaf,
            $order->idempotencyKey,
            null,
            null,
            $order->invoiceId,
            $order->externalReference,
            $order->payerPhone,
        ));

        return self::outcome($result->payment, $result->receipt, $result->replayed);
    }

    public function status(string $tenantId, string $establishmentId, string $paymentId): ?PaymentOutcome
    {
        $payment = $this->repository->payment($tenantId, $paymentId);
        if ($payment === null || $payment['establishment_id'] !== $establishmentId) {
            return null;
        }

        return self::outcome($payment, $this->repository->receiptForPayment($tenantId, $paymentId), false);
    }

    /**
     * @param array<string, mixed> $payment
     * @param array<string, mixed>|null $receipt
     */
    private static function outcome(array $payment, ?array $receipt, bool $replayed): PaymentOutcome
    {
        return new PaymentOutcome(
            (string) $payment['id'],
            (string) $payment['status'],
            (int) $payment['amount_xaf'],
            (string) $payment['channel'],
            isset($payment['invoice_id']) ? (string) $payment['invoice_id'] : null,
            isset($receipt['id']) ? (string) $receipt['id'] : null,
            isset($receipt['number']) ? (string) $receipt['number'] : null,
            isset($payment['external_reference']) ? (string) $payment['external_reference'] : null,
            $replayed,
        );
    }
}
