<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Numbering\SequenceService;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Domain\Numbers;
use Scholaris\Modules\Socle\Billing\Infrastructure\Db;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/** Référence de paiement unique par famille (ex. FAM-0587), valable pour mobile money et guichet ; envoyée au garant par SMS. */
final class PaymentReferenceService
{
    public function __construct(
        private readonly Db $db,
        private readonly SequenceService $sequences,
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly PersonDirectory $people,
        private readonly Messenger $messenger,
        private readonly BillingClock $clock,
        private readonly AuditLog $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> la référence existante du garant, ou une nouvelle */
    public function issueFor(string $tenantId, string $establishmentId, string $actorId, string $guarantorId): array
    {
        if ($this->people->find($tenantId, $guarantorId) === null) {
            throw HttpException::notFound('Garant introuvable.');
        }
        $existing = $this->payments->referenceOfGuarantor($tenantId, $guarantorId);
        if ($existing !== null) {
            return $existing;
        }

        $id = (string) new Ulid();
        $this->db->transaction(function () use ($tenantId, $establishmentId, $actorId, $guarantorId, $id): void {
            // La référence est unique pour toute la plateforme : l'opérateur mobile ne connaît pas le client.
            $number = $this->sequences->next('_platform', '_platform', 'family_reference', 0);
            $this->payments->insert('payment_references', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'guarantor_id' => $guarantorId,
                'reference' => Numbers::familyReference($number), 'created_at' => $this->clock->now(),
            ]);
            $this->audit->append($tenantId, $actorId, 'payment_reference.issued', 'payment_reference', $id, null, ['guarantor_id' => $guarantorId]);
        });

        $reference = $this->payments->referenceById($tenantId, $id) ?? [];
        try {
            $this->messenger->send($tenantId, $establishmentId, $guarantorId, 'billing.family_reference', ['reference' => (string) $reference['reference']], ['sms']);
        } catch (Throwable $e) {
            $this->logger->warning('SMS de référence famille non envoyé : ' . $e->getMessage());
        }

        return $reference;
    }

    /**
     * Solde d'une famille, tous établissements du client : par enfant, dû, retard et prochaine échéance ; crédit conservé.
     *
     * @return array<string, mixed>
     */
    public function balance(string $tenantId, string $reference): array
    {
        $row = $this->payments->referenceForTenant($tenantId, $reference) ?? throw HttpException::notFound('Référence de paiement inconnue.');
        $today = $this->clock->today();

        $children = [];
        $total = 0;
        $overdueTotal = 0;
        $next = null;
        foreach ($this->people->learnersOf($tenantId, (string) $row['guarantor_id']) as $learner) {
            $due = 0;
            $overdue = 0;
            $childNext = null;
            $byEstablishment = [];
            foreach ($this->invoices->outstandingFor($tenantId, [$learner->id]) as $installment) {
                $left = (int) $installment['amount_xaf'] - (int) $installment['paid_xaf'];
                $due += $left;
                $byEstablishment[(string) $installment['establishment_id']] = ($byEstablishment[(string) $installment['establishment_id']] ?? 0) + $left;
                if ((string) $installment['due_on'] < $today) {
                    $overdue += $left;
                }
                $childNext = $childNext === null || (string) $installment['due_on'] < $childNext ? (string) $installment['due_on'] : $childNext;
            }
            $total += $due;
            $overdueTotal += $overdue;
            $next = $childNext !== null && ($next === null || $childNext < $next) ? $childNext : $next;
            $children[] = [
                'learner_id' => $learner->id, 'name' => $learner->fullName(), 'due_xaf' => $due, 'overdue_xaf' => $overdue, 'next_due_on' => $childNext,
                'establishments' => array_map(
                    static fn (string $id, int $amount): array => ['establishment_id' => $id, 'due_xaf' => $amount],
                    array_keys($byEstablishment),
                    array_values($byEstablishment),
                ),
            ];
        }

        return [
            'reference' => (string) $row['reference'], 'guarantor_id' => (string) $row['guarantor_id'],
            'total_due_xaf' => $total, 'overdue_xaf' => $overdueTotal, 'credit_xaf' => $this->payments->creditOf($tenantId, (string) $row['id']),
            'next_due_on' => $next, 'children' => $children,
        ];
    }
}
