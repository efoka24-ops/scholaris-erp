<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Plans de paiement individuels [fees_private] (EX-FIN-05) : proposés par l'intendant (motif obligatoire), validés ou refusés par une
 * autre personne (séparation des tâches), annulables. Les factures et paiements restent dans le socle : le plan est un engagement
 * d'échéancier, qui lève le blocage de remise des documents tant qu'il est en vigueur (DocumentHoldGuard). Aucun plan ne conditionne
 * l'accès aux cours ni aux évaluations (RG-FIN-1).
 */
final class PaymentPlans
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REFUSED = 'refused';
    public const CANCELLED = 'cancelled';

    public function __construct(private readonly Store $store, private readonly SecondaryClock $clock)
    {
    }

    /**
     * @param list<array{due_on: string, amount: int}> $installments dates strictement croissantes
     * @return array<string, mixed>
     */
    public function propose(string $tenantId, string $establishmentId, string $personId, ?string $invoiceId, array $installments, string $reason, string $actorId): array
    {
        $previous = '';
        $total = 0;
        foreach ($installments as $index => $installment) {
            if ($installment['due_on'] <= $previous) {
                throw HttpException::validation(['installments.' . $index => ['Les échéances doivent être en dates strictement croissantes.']]);
            }
            $previous = $installment['due_on'];
            $total += $installment['amount'];
        }
        foreach ($this->store->select('se_payment_plans', $tenantId, $establishmentId, ['person_id' => $personId]) as $row) {
            if (in_array($row['status'], [self::PENDING, self::APPROVED], true) && ($row['invoice_id'] ?? null) === $invoiceId) {
                throw HttpException::conflict('Un plan en attente ou validé existe déjà pour cet élève et cette facture : annulez-le d\'abord.');
            }
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert('se_payment_plans', $tenantId, $establishmentId, [
            'id' => $id, 'person_id' => $personId, 'invoice_id' => $invoiceId,
            'installments' => json_encode($installments, JSON_THROW_ON_ERROR), 'total_amount' => $total,
            'reason' => $reason, 'status' => self::PENDING, 'requested_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->show($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function decide(string $tenantId, string $establishmentId, string $id, bool $approve, string $reason, string $actorId): array
    {
        $row = $this->store->findOrFail('se_payment_plans', $tenantId, $establishmentId, $id, 'Plan de paiement introuvable.');
        if ($row['status'] !== self::PENDING) {
            throw HttpException::conflict('Ce plan est déjà décidé.');
        }
        if ($row['requested_by'] === $actorId) {
            throw HttpException::forbidden('L\'auteur d\'un plan ne peut pas le valider (séparation des tâches).');
        }
        $now = $this->clock->now();
        $this->store->update('se_payment_plans', $tenantId, $establishmentId, [
            'status' => $approve ? self::APPROVED : self::REFUSED, 'approved_by' => $actorId, 'decided_at' => $now,
            'decision_reason' => $reason, 'updated_at' => $now,
        ], ['id' => $id]);

        return $this->show($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function cancel(string $tenantId, string $establishmentId, string $id, string $reason, string $actorId): array
    {
        $row = $this->store->findOrFail('se_payment_plans', $tenantId, $establishmentId, $id, 'Plan de paiement introuvable.');
        if (!in_array($row['status'], [self::PENDING, self::APPROVED], true)) {
            throw HttpException::conflict('Seul un plan en attente ou validé peut être annulé.');
        }
        $now = $this->clock->now();
        $this->store->update('se_payment_plans', $tenantId, $establishmentId, [
            'status' => self::CANCELLED, 'decided_at' => $now, 'decision_reason' => $reason, 'updated_at' => $now,
            'approved_by' => $row['approved_by'] ?? $actorId,
        ], ['id' => $id]);

        return $this->show($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function show(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->view($this->store->findOrFail('se_payment_plans', $tenantId, $establishmentId, $id, 'Plan de paiement introuvable.'));
    }

    /** Un plan validé dont la dernière échéance n'est pas passée est « en vigueur » : il lève le blocage de remise des documents. */
    public function hasPlanInGoodStanding(string $tenantId, string $establishmentId, string $personId): bool
    {
        $today = $this->clock->today();
        foreach ($this->store->select('se_payment_plans', $tenantId, $establishmentId, ['person_id' => $personId, 'status' => self::APPROVED]) as $row) {
            $view = $this->view($row);
            if ($view['ends_on'] !== null && $view['ends_on'] >= $today) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function view(array $row): array
    {
        $decoded = json_decode((string) ($row['installments'] ?? '[]'), true);
        $installments = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
        $last = $installments === [] ? null : end($installments);
        $today = $this->clock->today();
        $dueToDate = 0;
        foreach ($installments as $installment) {
            if ((string) ($installment['due_on'] ?? '') <= $today) {
                $dueToDate += (int) ($installment['amount'] ?? 0);
            }
        }

        return [
            'id' => (string) $row['id'],
            'person_id' => (string) $row['person_id'],
            'invoice_id' => $row['invoice_id'] ?? null,
            'installments' => $installments,
            'total_amount' => (int) $row['total_amount'],
            'due_to_date' => $dueToDate,
            'ends_on' => is_array($last) ? (string) ($last['due_on'] ?? '') : null,
            'reason' => (string) $row['reason'],
            'status' => (string) $row['status'],
            'in_force' => $row['status'] === self::APPROVED && is_array($last) && (string) ($last['due_on'] ?? '') >= $today,
            'requested_by' => (string) $row['requested_by'],
            'approved_by' => $row['approved_by'] ?? null,
            'decided_at' => $row['decided_at'] ?? null,
            'decision_reason' => $row['decision_reason'] ?? null,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
