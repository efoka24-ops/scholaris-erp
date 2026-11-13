<?php

declare(strict_types=1);

namespace Tests\Support\Secondary\Portals;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\StudentFacts;
use Scholaris\Modules\Apps\Secondary\Portals\Contracts\FamilyInvoices;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOrder;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOutcome;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\Portals\Contracts\AdultLearnerCheck;

/**
 * Doublures des contrats du socle et des points d'extension Secondary pour les tests des documents et des portails du secondaire
 * (E1-C) : comptes liés à des personnes, factures et paiements (idempotents par clé), soldes et blocages, majorité et consentement,
 * matricules fournis par un « Enrolment » fictif, factures payables fournies par un « Fees » fictif. Données fictives.
 */
final class PortalWorld implements UserPersonLink, InvoiceIssuer, PaymentRequester, DebtorDirectory, AdultLearnerCheck, ConsentChecker, StudentFacts, FamilyInvoices
{
    /** @var array<string, string> « client|utilisateur » => personne */
    public array $links = [];
    /** @var array<string, InvoiceSummary> */
    public array $invoices = [];
    /** @var array<string, PaymentOutcome> clé d'idempotence => paiement */
    public array $payments = [];
    /** @var array<string, int> élève => solde */
    public array $balances = [];
    /** @var array<string, true> élève => documents bloqués */
    public array $blocked = [];
    /** @var array<string, true> */
    public array $adults = [];
    /** @var array<string, true> */
    public array $consents = [];
    /** @var array<string, string> élève => matricule */
    public array $nationalIds = [];

    public function link(string $tenantId, string $userId, string $personId): void
    {
        $this->links[$tenantId . '|' . $userId] = $personId;
    }

    /** @param list<array{id: string, line_id: string, label: string, due_on: string, amount_xaf: int, paid_xaf: int}> $installments */
    public function invoice(string $id, string $establishmentId, string $learnerId, int $net, int $paid, ?string $enrollmentId = null, array $installments = []): void
    {
        $this->invoices[$id] = new InvoiceSummary(
            $id,
            $establishmentId,
            $learnerId,
            InvoiceSummary::KIND_ENROLLMENT,
            $enrollmentId,
            'REF-' . $id,
            'open',
            $net,
            0,
            $net,
            $paid,
            $net - $paid,
            null,
            [['id' => $id . '-l1', 'fee_item_id' => 'fi-1', 'fee_code' => 'tuition', 'kind' => 'tuition', 'label' => 'Pension (exemple)', 'gross_xaf' => $net, 'discount_xaf' => 0, 'net_xaf' => $net, 'paid_xaf' => $paid]],
            $installments,
        );
    }

    public function personIdForUser(string $tenantId, string $userId): ?string
    {
        return $this->links[$tenantId . '|' . $userId] ?? null;
    }

    public function forEnrollment(string $tenantId, string $establishmentId, string $actorId, string $enrollmentId, ?string $level = null, ?string $track = null, array $discounts = [], ?InvoicePayer $payer = null): InvoiceSummary
    {
        throw new \LogicException('Non utilisé.');
    }

    public function forCharges(ChargeInvoice $invoice): InvoiceSummary
    {
        throw new \LogicException('Non utilisé.');
    }

    public function addDiscount(string $tenantId, string $establishmentId, string $actorId, string $invoiceId, string $kind, string $mode, int $value, string $appliesTo, string $reason): InvoiceSummary
    {
        throw new \LogicException('Non utilisé.');
    }

    public function find(string $tenantId, string $establishmentId, string $invoiceId): ?InvoiceSummary
    {
        $invoice = $this->invoices[$invoiceId] ?? null;

        return $invoice !== null && $invoice->establishmentId === $establishmentId ? $invoice : null;
    }

    public function findByReference(string $tenantId, string $establishmentId, string $reference): ?InvoiceSummary
    {
        return null;
    }

    public function request(PaymentOrder $order): PaymentOutcome
    {
        if (isset($this->payments[$order->idempotencyKey])) {
            $p = $this->payments[$order->idempotencyKey];

            return new PaymentOutcome($p->paymentId, $p->status, $p->amountXaf, $p->channel, $p->invoiceId, null, null, null, true);
        }
        if (!in_array($order->channel, ['orange_money', 'mtn_momo'], true)) {
            throw HttpException::validation(['channel' => ['Canal invalide.']]);
        }

        return $this->payments[$order->idempotencyKey] = new PaymentOutcome('pay-' . count($this->payments), PaymentOutcome::PENDING, $order->amountXaf, $order->channel, $order->invoiceId, null, null, null);
    }

    public function status(string $tenantId, string $establishmentId, string $paymentId): ?PaymentOutcome
    {
        foreach ($this->payments as $payment) {
            if ($payment->paymentId === $paymentId) {
                return $payment;
            }
        }

        return null;
    }

    public function learnerIdsInArrears(string $tenantId, string $establishmentId): array
    {
        return array_keys(array_filter($this->balances, static fn (int $b): bool => $b > 0));
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $learnerId): int
    {
        return $this->balances[$learnerId] ?? 0;
    }

    public function isBlocked(string $tenantId, string $establishmentId, string $learnerId, string $scope): bool
    {
        return $scope === 'documents' && isset($this->blocked[$learnerId]);
    }

    public function isAdult(string $tenantId, string $personId): bool
    {
        return isset($this->adults[$personId]);
    }

    public function hasConsent(string $tenantId, string $personId, string $purpose): bool
    {
        return isset($this->consents[$personId]);
    }

    public function nationalId(string $tenantId, string $establishmentId, string $personId): ?string
    {
        return $this->nationalIds[$personId] ?? null;
    }

    public function openFor(string $tenantId, string $establishmentId, string $learnerId): array
    {
        return array_values(array_filter($this->invoices, static fn (InvoiceSummary $i): bool => $i->subjectId === $learnerId));
    }
}
