<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;
use Scholaris\Modules\Socle\Billing\Domain\Amounts;
use Scholaris\Modules\Socle\Billing\Infrastructure\CatalogRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\Db;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/** Génération des factures depuis les grilles, remises et bourses (appliquées à la pension par défaut). */
final class InvoiceService
{
    public const DISCOUNT_KINDS = ['sibling', 'staff', 'merit', 'waiver'];

    public function __construct(
        private readonly Db $db,
        private readonly InvoiceRepository $invoices,
        private readonly CatalogRepository $catalog,
        private readonly EnrollmentDirectory $enrollments,
        private readonly SiblingDiscountResolver $siblings,
        private readonly BillingSettings $settings,
        private readonly BillingClock $clock,
        private readonly AuditLog $audit,
        private readonly Approvals $approvals,
        private readonly AllocationService $allocation,
    ) {
    }

    /**
     * @param list<array{kind: string, mode: string, value: int, applies_to?: string, reason: string}> $discounts
     * @return array<string, mixed> identifiant de la facture créée
     */
    public function generate(string $tenantId, string $establishmentId, string $actorId, string $enrollmentId, ?string $level, ?string $track, array $discounts = [], ?InvoicePayer $payer = null): array
    {
        $enrollment = $this->enrollments->find($tenantId, $enrollmentId);
        if ($enrollment === null || $enrollment->tenantId !== $tenantId || $enrollment->establishmentId !== $establishmentId) {
            throw HttpException::notFound('Inscription introuvable dans cet établissement.');
        }
        if (!in_array($enrollment->status, [EnrollmentInfo::ACTIVE, 'conditional'], true)) {
            throw HttpException::validation(['enrollment_id' => ['L\'inscription n\'est pas active : aucune facture ne peut être émise.']]);
        }
        if ($this->invoices->findForEnrollment($tenantId, $enrollmentId) !== null) {
            throw HttpException::conflict('Cette inscription a déjà une facture.');
        }

        $schedules = $this->catalog->applicableSchedules($tenantId, $establishmentId, $enrollment->academicYearId, $enrollment->structureId, $level, $track);
        if ($schedules === []) {
            throw HttpException::validation(['enrollment_id' => ['Aucune grille de frais ne correspond à cette inscription : créez-la d\'abord.']]);
        }

        $invoiceId = $this->db->transaction(function () use ($tenantId, $establishmentId, $actorId, $enrollment, $schedules, $discounts, $payer): string {
            $id = (string) new Ulid();
            $now = $this->clock->now();
            $this->invoices->insert('invoices', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'enrollment_id' => $enrollment->id,
                'learner_id' => $enrollment->personId, 'academic_year_id' => $enrollment->academicYearId, 'structure_id' => $enrollment->structureId,
                'status' => 'open', 'gross_xaf' => 0, 'discount_xaf' => 0, 'net_xaf' => 0, 'paid_xaf' => 0, 'balance_xaf' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ] + self::payerColumns($payer));

            foreach ($schedules as $schedule) {
                $this->addLine($tenantId, $establishmentId, $id, $enrollment->personId, $schedule);
            }
            $this->invoices->recompute($tenantId, $id, $now);

            $sibling = $this->settings->siblingDiscount($tenantId, $establishmentId);
            if ($sibling['value'] > 0 && $this->siblings->isEligible($tenantId, $enrollment->personId)) {
                $this->addDiscount($tenantId, $establishmentId, $actorId, $id, 'sibling', $sibling['mode'], $sibling['value'], 'tuition', 'Remise de fratrie');
            }
            foreach ($discounts as $discount) {
                $this->addDiscount($tenantId, $establishmentId, $actorId, $id, $discount['kind'], $discount['mode'], $discount['value'], $discount['applies_to'] ?? 'tuition', $discount['reason']);
            }

            $invoice = $this->invoices->find($tenantId, $id) ?? [];
            $this->audit->append($tenantId, $actorId, 'invoice.created', 'invoice', $id, null, [
                'learner_id' => $enrollment->personId, 'net_xaf' => $invoice['net_xaf'] ?? 0, 'discount_xaf' => $invoice['discount_xaf'] ?? 0,
            ]);

            return $id;
        });

        $this->allocation->applyCreditForLearner($tenantId, $enrollment->personId);

        return $this->detail($tenantId, $invoiceId);
    }

    /**
     * Facture libre sans inscription (D4, S13) : un candidat ou une personne, des frais du catalogue désignés par code ou identifiant,
     * une échéance par ligne. Idempotente : la même référence renvoie la facture déjà émise (même titulaire), sinon conflit.
     *
     * @return array<string, mixed>
     */
    public function generateCharges(ChargeInvoice $order): array
    {
        $tenantId = $order->tenantId;
        $establishmentId = $order->establishmentId;
        if (preg_match('/^[A-Za-z0-9_.:-]{1,36}$/', $order->reference) !== 1) {
            throw HttpException::validation(['reference' => ['Référence : 1 à 36 caractères (lettres, chiffres, « _ . : - »).']]);
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $order->subjectId) !== 1 || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $order->academicYearId) !== 1) {
            throw HttpException::validation(['subject_id' => ['Identifiant de personne ou d\'année invalide.']]);
        }
        if ($order->lines === [] || count($order->lines) > 20) {
            throw HttpException::validation(['lines' => ['Une facture libre a de 1 à 20 lignes.']]);
        }
        $key = self::chargeKey($order->reference);
        $existing = $this->invoices->findForEnrollment($tenantId, $key);
        if ($existing !== null) {
            if ($existing['establishment_id'] !== $establishmentId || $existing['learner_id'] !== $order->subjectId) {
                throw HttpException::conflict('Cette référence de facture est déjà utilisée pour une autre personne.');
            }

            return $this->detail($tenantId, (string) $existing['id']);
        }

        $resolved = [];
        foreach ($order->lines as $index => $line) {
            $item = $line->feeCode !== null
                ? $this->catalog->findByCode($tenantId, $establishmentId, $line->feeCode)
                : ($line->feeItemId !== null ? $this->catalog->find(CatalogRepository::ITEMS, $tenantId, $establishmentId, $line->feeItemId) : null);
            if ($item === null || (int) $item['active'] !== 1) {
                throw HttpException::validation(['lines.' . $index => ['Frais introuvable ou inactif dans cet établissement.']]);
            }
            $amount = $line->amountXaf ?? (int) $item['amount_xaf'];
            $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $line->dueOn);
            if ($amount < 0 || $amount > 100000000 || $due === false || $due->format('Y-m-d') !== $line->dueOn) {
                throw HttpException::validation(['lines.' . $index => ['Montant entier positif en XAF et échéance AAAA-MM-JJ attendus.']]);
            }
            $label = $line->label !== null ? trim($line->label) : (string) $item['name'];
            if ($label === '' || mb_strlen($label) > 160) {
                throw HttpException::validation(['lines.' . $index => ['Libellé de 1 à 160 caractères.']]);
            }
            $resolved[] = ['fee_item_id' => (string) $item['id'], 'item_kind' => (string) $item['kind'], 'item_name' => $label,
                'item_code' => $item['code'] ?? null, 'amount_xaf' => $amount, 'terms' => json_encode([['due_on' => $line->dueOn, 'amount' => $amount]], JSON_THROW_ON_ERROR)];
        }

        try {
            $invoiceId = $this->db->transaction(function () use ($order, $key, $resolved): string {
                $id = (string) new Ulid();
                $now = $this->clock->now();
                $this->invoices->insert('invoices', [
                    'id' => $id, 'tenant_id' => $order->tenantId, 'establishment_id' => $order->establishmentId, 'enrollment_id' => $key,
                    'learner_id' => $order->subjectId, 'academic_year_id' => $order->academicYearId, 'structure_id' => $order->structureId,
                    'status' => 'open', 'gross_xaf' => 0, 'discount_xaf' => 0, 'net_xaf' => 0, 'paid_xaf' => 0, 'balance_xaf' => 0,
                    'created_at' => $now, 'updated_at' => $now, 'invoice_kind' => InvoiceSummary::KIND_CHARGES,
                ] + self::payerColumns($order->payer));
                foreach ($resolved as $schedule) {
                    $this->addLine($order->tenantId, $order->establishmentId, $id, $order->subjectId, $schedule);
                }
                $this->invoices->recompute($order->tenantId, $id, $now);
                $invoice = $this->invoices->find($order->tenantId, $id) ?? [];
                $this->audit->append($order->tenantId, $order->actorId, 'invoice.created', 'invoice', $id, null, [
                    'learner_id' => $order->subjectId, 'net_xaf' => $invoice['net_xaf'] ?? 0, 'kind' => InvoiceSummary::KIND_CHARGES, 'reference' => $order->reference,
                    'payer' => $order->payer?->name,
                ]);

                return $id;
            });
        } catch (PDOException $e) {
            $again = $this->db->isUniqueViolation($e) ? $this->invoices->findForEnrollment($tenantId, $key) : null;
            if ($again === null || $again['learner_id'] !== $order->subjectId) {
                throw $e;
            }
            $invoiceId = (string) $again['id'];
        }

        return $this->detail($tenantId, $invoiceId);
    }

    /** Clé technique d'une facture libre dans la colonne enrollment_id (unique par client), comme « carry: » pour les reports. */
    public static function chargeKey(string $reference): string
    {
        return 'ref:' . $reference;
    }

    /** @return array<string, string|null> */
    private static function payerColumns(?InvoicePayer $payer): array
    {
        return $payer === null ? [] : ['payer_kind' => $payer->kind, 'payer_name' => $payer->name, 'payer_ref' => $payer->reference];
    }

    /** @param array<string, mixed> $schedule */
    private function addLine(string $tenantId, string $establishmentId, string $invoiceId, string $learnerId, array $schedule): void
    {
        $lineId = (string) new Ulid();
        $amount = (int) $schedule['amount_xaf'];
        $kind = (string) $schedule['item_kind'];
        $name = (string) $schedule['item_name'];
        $this->invoices->insert('invoice_lines', [
            'id' => $lineId, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'invoice_id' => $invoiceId,
            'fee_item_id' => (string) $schedule['fee_item_id'], 'kind' => $kind, 'label' => $name,
            'gross_xaf' => $amount, 'discount_xaf' => 0, 'net_xaf' => $amount,
        ] + (($schedule['item_code'] ?? null) !== null ? ['fee_code' => (string) $schedule['item_code']] : []));

        /** @var list<array{due_on: string, amount: int}> $terms */
        $terms = json_decode((string) $schedule['terms'], true, flags: JSON_THROW_ON_ERROR);
        foreach ($terms as $index => $term) {
            $this->invoices->insert('installments', [
                'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'invoice_id' => $invoiceId,
                'line_id' => $lineId, 'learner_id' => $learnerId, 'kind' => $kind, 'seq_no' => $index + 1,
                'label' => count($terms) > 1 ? sprintf('%s · tranche %d', $name, $index + 1) : $name,
                'due_on' => $term['due_on'], 'amount_xaf' => $term['amount'], 'paid_xaf' => 0,
            ]);
        }
    }

    /**
     * Ajoute une remise à une facture. L'exonération attend la validation d'une autre personne ; les autres s'appliquent aussitôt.
     *
     * @return array<string, mixed> la remise
     */
    public function addDiscount(string $tenantId, string $establishmentId, string $actorId, string $invoiceId, string $kind, string $mode, int $value, string $appliesTo, string $reason): array
    {
        $invoice = $this->invoices->find($tenantId, $invoiceId);
        if ($invoice === null || $invoice['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Facture introuvable.');
        }
        if ($invoice['status'] === 'cancelled') {
            throw HttpException::conflict('Cette facture est annulée.');
        }
        if (!in_array($kind, self::DISCOUNT_KINDS, true) || !in_array($mode, ['percent', 'fixed'], true) || $value < 0 || ($mode === 'percent' && $value > 100)) {
            throw HttpException::validation(['discount' => ['Type, mode ou valeur de remise invalide.']]);
        }
        $targets = $this->settings->discountTargets($tenantId, $establishmentId);
        if ($targets !== null && !in_array($appliesTo, $targets, true)) {
            throw HttpException::validation(['applies_to' => ['Les remises ne portent que sur : ' . implode(', ', $targets) . ' (paramètre billing.discount_applies_to).']]);
        }
        $needsApproval = $this->checkCap($tenantId, $establishmentId, $invoiceId, $mode, $value, $appliesTo);

        return $this->db->transaction(function () use ($tenantId, $establishmentId, $actorId, $invoice, $invoiceId, $kind, $mode, $value, $appliesTo, $reason, $needsApproval): array {
            $id = (string) new Ulid();
            $pending = $kind === 'waiver' || $needsApproval;
            $approvalId = $pending ? $this->approvals->request($tenantId, $kind === 'waiver' ? 'fee.waiver' : 'fee.discount', 'discount', $id, $reason, $actorId) : null;
            $this->invoices->insert('discounts', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'invoice_id' => $invoiceId,
                'learner_id' => (string) $invoice['learner_id'], 'kind' => $kind, 'mode' => $mode, 'rate_value' => $value,
                'applies_to' => $appliesTo, 'amount_xaf' => 0, 'status' => $pending ? 'pending' : 'approved', 'reason' => $reason,
                'approval_id' => $approvalId, 'requested_by' => $actorId, 'approved_by' => null, 'created_at' => $this->clock->now(),
            ]);
            if (!$pending) {
                $this->apply($tenantId, $id);
            }
            $this->audit->append($tenantId, $actorId, 'discount.' . ($pending ? 'requested' : 'applied'), 'discount', $id, null, [
                'invoice_id' => $invoiceId, 'kind' => $kind, 'mode' => $mode, 'value' => $value, 'reason' => $reason,
            ]);

            return $this->invoices->discount($tenantId, $id) ?? [];
        });
    }

    /**
     * Plafond des remises cumulées (S11) : la somme des remises appliquées ou en attente sur la base visée, plus la nouvelle,
     * ne dépasse pas `billing.discount_cap_percent` de cette base (refus 422). Renvoie vrai si le cumul dépasse
     * `billing.discount_approval_percent` (la remise attend alors une validation). Sans ces paramètres : aucun contrôle.
     */
    private function checkCap(string $tenantId, string $establishmentId, string $invoiceId, string $mode, int $value, string $appliesTo): bool
    {
        $cap = $this->settings->discountCapPercent($tenantId, $establishmentId);
        $threshold = $this->settings->discountApprovalPercent($tenantId, $establishmentId);
        if ($cap === null && $threshold === null) {
            return false;
        }
        $totals = $this->invoices->discountBase($tenantId, $invoiceId, $appliesTo);
        $base = $totals['base'];
        $cumulated = $totals['discounted'] + Amounts::discount($mode, $value, $base);
        foreach ($this->invoices->discounts($tenantId, $invoiceId) as $other) {
            if ($other['status'] === 'pending' && $other['applies_to'] === $appliesTo) {
                $cumulated += Amounts::discount((string) $other['mode'], (int) $other['rate_value'], $base);
            }
        }
        if ($cap !== null && $cumulated * 100 > $base * $cap) {
            throw HttpException::validation(['value' => [sprintf(
                'Plafond des remises et bourses dépassé : %d FCFA cumulés pour un plafond de %d %% de %d FCFA.',
                $cumulated,
                $cap,
                $base,
            )]]);
        }

        return $threshold !== null && $cumulated * 100 > $base * $threshold;
    }

    /** Valide une exonération (par une autre personne que le demandeur) et l'applique. */
    public function approveDiscount(string $tenantId, string $establishmentId, string $approverId, string $discountId): void
    {
        $discount = $this->invoices->discount($tenantId, $discountId);
        if ($discount === null || $discount['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Remise introuvable.');
        }
        if ($discount['status'] !== 'pending') {
            throw HttpException::conflict('Cette remise a déjà été traitée.');
        }

        $this->db->transaction(function () use ($tenantId, $approverId, $discount, $discountId): void {
            $this->approvals->approve($tenantId, (string) $discount['approval_id'], $approverId);
            $this->db->update('discounts', ['status' => 'approved', 'approved_by' => $approverId], ['tenant_id' => $tenantId, 'id' => $discountId]);
            $this->apply($tenantId, $discountId);
            $this->audit->append($tenantId, $approverId, 'discount.approved', 'discount', $discountId, ['status' => 'pending'], ['status' => 'approved']);
        });
    }

    /** Applique une remise approuvée : base sur le brut des lignes visées, répartie sur les tranches restantes. */
    private function apply(string $tenantId, string $discountId): void
    {
        $discount = $this->invoices->discount($tenantId, $discountId) ?? throw HttpException::notFound('Remise introuvable.');
        $invoiceId = (string) $discount['invoice_id'];
        $installments = $this->invoices->discountableInstallments($tenantId, $invoiceId, (string) $discount['applies_to']);

        $gross = 0;
        $seen = [];
        $outstanding = [];
        foreach ($installments as $row) {
            if (!isset($seen[$row['line_id']])) {
                $seen[$row['line_id']] = true;
                $gross += (int) $row['line_gross'];
            }
            $outstanding[] = max(0, (int) $row['amount_xaf'] - (int) $row['paid_xaf']);
        }

        $wanted = Amounts::discount((string) $discount['mode'], (int) $discount['rate_value'], $gross);
        $shares = Amounts::spread($wanted, $outstanding);
        $applied = 0;
        foreach ($installments as $index => $row) {
            if ($shares[$index] > 0) {
                $this->invoices->reduceInstallment($tenantId, (string) $row['id'], $shares[$index]);
                $this->invoices->addToLineDiscount($tenantId, (string) $row['line_id'], $shares[$index]);
                $applied += $shares[$index];
            }
        }

        $this->db->update('discounts', ['amount_xaf' => $applied], ['tenant_id' => $tenantId, 'id' => $discountId]);
        $this->invoices->recompute($tenantId, $invoiceId, $this->clock->now());
    }

    /** @return array<string, mixed> */
    public function detail(string $tenantId, string $invoiceId): array
    {
        $invoice = $this->invoices->find($tenantId, $invoiceId) ?? throw HttpException::notFound('Facture introuvable.');
        $today = $this->clock->today();
        $installments = array_map(static function (array $row) use ($today): array {
            $left = (int) $row['amount_xaf'] - (int) $row['paid_xaf'];
            $row['status'] = $left <= 0 ? 'paid' : ((int) $row['paid_xaf'] > 0 ? 'partial' : 'pending');
            $row['overdue'] = $left > 0 && (string) $row['due_on'] < $today;

            return $row;
        }, $this->invoices->installments($tenantId, $invoiceId));

        return $invoice + [
            'lines' => $this->invoices->lines($tenantId, $invoiceId),
            'installments' => $installments,
            'discounts' => $this->invoices->discounts($tenantId, $invoiceId),
        ];
    }
}
