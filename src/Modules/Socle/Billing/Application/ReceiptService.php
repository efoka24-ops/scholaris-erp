<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Numbering\SequenceService;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Billing\Domain\Numbers;
use Scholaris\Modules\Socle\Billing\Infrastructure\Db;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;
use Scholaris\Modules\Socle\Integrations\Contracts\PdfRenderer;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/** Reçus : numéro sans trou par établissement et année (dans la transaction du paiement), PDF à la demande, SMS après validation. */
final class ReceiptService
{
    public function __construct(
        private readonly Db $db,
        private readonly SequenceService $sequences,
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly AllocationService $allocation,
        private readonly BillingSettings $settings,
        private readonly BillingClock $clock,
        private readonly EstablishmentDirectory $establishments,
        private readonly PersonDirectory $people,
        private readonly Messenger $messenger,
        private readonly PdfRenderer $pdf,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Émet le reçu d'un paiement confirmé. Le numéro est pris dans la transaction en cours : si elle échoue, il n'est pas consommé.
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    public function issue(string $tenantId, array $payment): array
    {
        $establishmentId = (string) $payment['establishment_id'];
        $year = $this->clock->year();
        $sequence = $this->sequences->next($tenantId, $establishmentId, 'receipt', $year);
        $number = Numbers::receipt($this->code($tenantId, $establishmentId), $year, $sequence);

        $id = (string) new Ulid();
        $this->payments->insert('receipts', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'payment_id' => (string) $payment['id'],
            'year' => $year, 'seq_no' => $sequence, 'number' => $number, 'amount_xaf' => (int) $payment['amount_xaf'],
            'status' => 'valid', 'issued_at' => $this->clock->now(), 'pdf_path' => null, 'sms_sent_at' => null,
        ]);

        return $this->payments->receipt($tenantId, $id) ?? [];
    }

    public function code(string $tenantId, string $establishmentId): string
    {
        $configured = $this->settings->receiptCode($tenantId, $establishmentId);
        if ($configured !== null) {
            return $configured;
        }
        $establishment = $this->establishments->find($tenantId, $establishmentId);

        return Numbers::codeFromName($establishment === null ? '' : $establishment->name);
    }

    /**
     * Envoie le SMS du reçu au garant. Un échec d'envoi ne remet jamais en cause le paiement.
     *
     * @param array<string, mixed> $receipt
     * @param array<string, mixed> $payment
     */
    public function notify(string $tenantId, array $receipt, array $payment): void
    {
        try {
            $balance = array_sum(array_map(
                static fn (array $row): int => (int) $row['amount_xaf'] - (int) $row['paid_xaf'],
                $this->invoices->outstandingFor($tenantId, $this->allocation->learnersOf($tenantId, $payment)),
            ));
            foreach ($this->recipients($tenantId, $payment) as $personId) {
                $result = $this->messenger->send($tenantId, (string) $receipt['establishment_id'], $personId, 'billing.receipt', [
                    'receipt_number' => (string) $receipt['number'], 'amount' => (int) $receipt['amount_xaf'], 'balance' => $balance,
                ], ['sms']);
                if ($result->status !== DeliveryResult::BLOCKED) {
                    $this->payments->markReceiptSms($tenantId, (string) $receipt['id'], $this->clock->now());
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('SMS de reçu non envoyé : ' . $e->getMessage(), ['receipt' => $receipt['id'] ?? null]);
        }
    }

    /**
     * @param array<string, mixed> $payment
     * @return list<string>
     */
    private function recipients(string $tenantId, array $payment): array
    {
        if ($payment['payer_reference_id'] !== null) {
            $reference = $this->payments->referenceById($tenantId, (string) $payment['payer_reference_id']);

            return $reference === null ? [] : [(string) $reference['guarantor_id']];
        }
        $ids = [];
        foreach ($this->allocation->learnersOf($tenantId, $payment) as $learnerId) {
            foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guarantor) {
                $ids[$guarantor->id] = true;
            }
        }

        return array_map('strval', array_keys($ids));
    }

    /** @return string contenu binaire du PDF du reçu */
    public function pdf(string $tenantId, string $establishmentId, string $receiptId): string
    {
        $receipt = $this->payments->receipt($tenantId, $receiptId);
        if ($receipt === null || $receipt['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Reçu introuvable.');
        }

        return $this->pdf->render($this->html($tenantId, $receipt));
    }

    /** @param array<string, mixed> $receipt */
    private function html(string $tenantId, array $receipt): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $payment = $this->payments->payment($tenantId, (string) $receipt['payment_id']) ?? [];
        $establishment = $this->establishments->find($tenantId, (string) $receipt['establishment_id']);
        $lines = $this->db->all(
            'SELECT i.label, i.learner_id, a.amount_xaf FROM allocations a JOIN installments i ON i.id = a.installment_id AND i.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND a.payment_id = ? AND a.amount_xaf > 0 ORDER BY a.id',
            [$tenantId, (string) $receipt['payment_id']],
        );

        $rows = '';
        foreach ($lines as $line) {
            $rows .= '<tr><td>' . $e($line['label']) . '</td><td style="text-align:right">' . $e(number_format((int) $line['amount_xaf'], 0, ',', ' ')) . ' FCFA</td></tr>';
        }
        $state = $receipt['status'] === 'cancelled' ? '<p><strong>REÇU ANNULÉ</strong></p>' : '';

        return '<!doctype html><html lang="fr"><meta charset="utf-8"><body>'
            . '<h1>' . $e(($establishment === null ? '' : $establishment->name)) . '</h1>'
            . '<h2>Reçu n° ' . $e($receipt['number']) . '</h2>' . $state
            . '<p>Date : ' . $e($receipt['issued_at']) . '<br>Mode : ' . $e($payment['channel'] ?? '') . '</p>'
            . '<table>' . $rows . '</table>'
            . '<p><strong>Total : ' . $e(number_format((int) $receipt['amount_xaf'], 0, ',', ' ')) . ' FCFA</strong></p>'
            . '</body></html>';
    }
}
