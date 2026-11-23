<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Staff\Contracts\PayableHour;
use Scholaris\Modules\Socle\Staff\Contracts\PayableStatement;
use Scholaris\Modules\Socle\Staff\Domain\StaffKind;
use Scholaris\Modules\Socle\Staff\Domain\VacationCalc;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoStore;

/**
 * Relevé mensuel de vacations d'un enseignant : somme des heures VALIDÉES du mois, équivalent TD et montant brut en XAF.
 * États : draft, pending_approval, validated, paid. Le montant engage l'établissement : sa validation passe par Approvals
 * (le demandeur ne peut pas approuver). Le paiement n'est qu'un état : aucun calcul de paie complète, aucune retenue.
 */
final class StatementService
{
    public const KIND = 'vacation.statement';

    public function __construct(
        private readonly PdoStore $store,
        private readonly StaffClock $clock,
        private readonly StaffService $staff,
        private readonly ContractService $contracts,
        private readonly RateService $rates,
        private readonly Approvals $approvals,
        private readonly VacationCalculatorService $calculator,
        private readonly PayabilityChecks $checks,
        private readonly HoursService $hours,
    ) {
    }

    /**
     * Crée (ou recalcule, tant qu'il est en brouillon) le relevé d'un mois.
     *
     * @return array<string, mixed>
     */
    public function generate(string $tenantId, string $establishmentId, string $staffId, string $period, string $actorId): array
    {
        [$first, $last] = VacationCalc::monthBounds($period);
        $member = $this->staff->get($tenantId, $establishmentId, $staffId);
        if ($member['kind'] === StaffKind::Permanent->value) {
            throw HttpException::conflict('Un enseignant permanent n\'est pas payé à la vacation.');
        }
        $existing = $this->store->where('vacation_statements', $tenantId, ['establishment_id' => $establishmentId, 'staff_id' => $staffId, 'period' => $period], 'id', 1)[0] ?? null;
        if ($existing !== null && $existing['status'] !== 'draft') {
            throw HttpException::conflict('Le relevé de ce mois est déjà soumis : il ne peut plus être recalculé.');
        }

        return $this->store->transaction(function () use ($tenantId, $establishmentId, $staffId, $period, $first, $last, $member, $existing, $actorId): array {
            if ($existing !== null) {
                $this->release($tenantId, (string) $existing['id']);
            }
            $hours = $this->store->rows(
                'SELECT * FROM vacation_hours WHERE tenant_id = ? AND establishment_id = ? AND staff_id = ? AND status = ? AND statement_id IS NULL AND held_on >= ? AND held_on <= ? ORDER BY id',
                [$tenantId, $establishmentId, $staffId, 'validated', $first, $last],
            );
            if ($hours === []) {
                throw HttpException::conflict('Aucune heure validée à payer pour cette période.');
            }
            $contractIds = array_values(array_unique(array_map(static fn (array $h): string => (string) $h['contract_id'], $hours)));
            if (count($contractIds) !== 1) {
                throw HttpException::conflict('Les heures de cette période relèvent de plusieurs contrats : validez-les contrat par contrat.');
            }
            $contract = $this->contracts->get($tenantId, $establishmentId, $contractIds[0]);
            // minutes par niveau d'enseignement (D15) puis par nature de séance ; '' = niveau non précisé
            $byLevel = [];
            $minutes = 0;
            foreach ($hours as $hour) {
                $level = (string) ($hour['level'] ?? '');
                $kind = (string) $hour['session_kind'];
                $byLevel[$level][$kind] = ($byLevel[$level][$kind] ?? 0) + (int) $hour['minutes'];
                $minutes += (int) $hour['minutes'];
            }
            $coefficients = $this->calculator->coefficients($tenantId, $establishmentId);
            $hetd = 0;
            $gross = 0;
            $rates = [];
            foreach ($byLevel as $level => $byKind) {
                $rate = $this->rates->hourlyRate($tenantId, $establishmentId, $contract, $member, $level === '' ? null : (string) $level);
                if ($rate === null) {
                    throw HttpException::conflict('Aucun taux de vacation applicable à cet enseignant.');
                }
                $levelHetd = VacationCalc::hetdMilli($byKind, $coefficients);
                $hetd += $levelHetd;
                $gross += VacationCalc::amount($levelHetd, $rate);
                $rates[$rate] = true;
            }
            // plusieurs taux (niveaux différents) : taux moyen pondéré, le montant restant la somme exacte par niveau
            $rate = count($rates) === 1 ? (int) array_key_first($rates) : ($hetd === 0 ? 0 : intdiv($gross * 1000 + intdiv($hetd, 2), $hetd));
            $now = $this->clock->now();
            $values = [
                'contract_id' => $contractIds[0], 'minutes_total' => $minutes, 'hetd_milli' => $hetd, 'hourly_rate' => $rate,
                'gross_amount' => $gross, 'updated_at' => $now,
            ];
            if ($existing !== null) {
                $id = (string) $existing['id'];
                $this->store->update('vacation_statements', $tenantId, $id, $values);
            } else {
                $id = (string) new Ulid();
                $this->store->insert('vacation_statements', $values + [
                    'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'staff_id' => $staffId, 'period' => $period,
                    'status' => 'draft', 'approval_id' => null, 'validated_by' => null, 'validated_at' => null, 'paid_on' => null,
                    'payment_reference' => null, 'created_by' => $actorId, 'created_at' => $now,
                ]);
            }
            foreach ($hours as $hour) {
                $this->store->update('vacation_hours', $tenantId, (string) $hour['id'], ['statement_id' => $id]);
            }

            return $this->get($tenantId, $establishmentId, $id);
        });
    }

    /** @return array<string, mixed> */
    public function submit(string $tenantId, string $establishmentId, string $id, string $actorId): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== 'draft') {
            throw HttpException::conflict('Ce relevé a déjà été soumis.');
        }
        $approvalId = $this->approvals->request(
            $tenantId,
            self::KIND,
            'vacation_statement',
            $id,
            sprintf('Relevé de vacations %s : %d XAF', $row['period'], (int) $row['gross_amount']),
            $actorId,
        );
        $this->store->update('vacation_statements', $tenantId, $id, ['status' => 'pending_approval', 'approval_id' => $approvalId, 'updated_at' => $this->clock->now()]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function approve(string $tenantId, string $establishmentId, string $id, string $approverId): array
    {
        $row = $this->pending($tenantId, $establishmentId, $id);
        $this->checks->assertStatementPayable($this->payable($tenantId, $row), $approverId);
        $this->approvals->approve($tenantId, (string) $row['approval_id'], $approverId);
        $now = $this->clock->now();
        $this->store->update('vacation_statements', $tenantId, $id, ['status' => 'validated', 'validated_by' => $approverId, 'validated_at' => $now, 'updated_at' => $now]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * Refus : le relevé redevient un brouillon, recalculable.
     *
     * @return array<string, mixed>
     */
    public function reject(string $tenantId, string $establishmentId, string $id, string $approverId): array
    {
        $row = $this->pending($tenantId, $establishmentId, $id);
        $this->approvals->reject($tenantId, (string) $row['approval_id'], $approverId);
        $this->store->update('vacation_statements', $tenantId, $id, ['status' => 'draft', 'approval_id' => null, 'updated_at' => $this->clock->now()]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function pay(string $tenantId, string $establishmentId, string $id, string $paidOn, string $reference): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== 'validated') {
            throw HttpException::conflict('Seul un relevé validé peut être payé.');
        }
        $this->store->update('vacation_statements', $tenantId, $id, ['status' => 'paid', 'paid_on' => $paidOn, 'payment_reference' => $reference, 'updated_at' => $this->clock->now()]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->store->find('vacation_statements', $tenantId, $id);
        if ($row === null || $row['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Relevé de vacations introuvable.');
        }

        return $row;
    }

    /** @return list<array<string, mixed>> heures du relevé */
    public function lines(string $tenantId, string $statementId): array
    {
        return $this->store->where('vacation_hours', $tenantId, ['statement_id' => $statementId], 'held_on');
    }

    /**
     * @param list<string>|null $restrictTo fiches visibles ; null = tout l'établissement
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    public function search(string $tenantId, string $establishmentId, ListQuery $query, ?array $restrictTo): array
    {
        if ($restrictTo === []) {
            return [[], null];
        }
        $where = ['tenant_id = ?', 'establishment_id = ?'];
        $params = [$tenantId, $establishmentId];
        $after = ListQuery::decodeCursor($query->cursor);
        if ($after !== null) {
            $where[] = 'id > ?';
            $params[] = $after;
        }
        foreach (['staff_id', 'status', 'period'] as $field) {
            if (isset($query->filters[$field])) {
                $where[] = $field . ' = ?';
                $params[] = $query->filters[$field];
            }
        }
        if ($restrictTo !== null) {
            $where[] = 'staff_id IN (' . implode(', ', array_fill(0, count($restrictTo), '?')) . ')';
            array_push($params, ...$restrictTo);
        }
        $rows = $this->store->rows(sprintf('SELECT * FROM vacation_statements WHERE %s ORDER BY id LIMIT %d', implode(' AND ', $where), $query->perPage + 1), $params);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return [$rows, $next];
    }

    /** @return array<string, mixed> */
    private function pending(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== 'pending_approval' || $row['approval_id'] === null) {
            throw HttpException::conflict('Ce relevé n\'attend pas de validation.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row ligne de vacation_statements */
    private function payable(string $tenantId, array $row): PayableStatement
    {
        $member = $this->staff->inTenant($tenantId, (string) $row['staff_id']);

        return new PayableStatement(
            (string) $row['id'],
            $tenantId,
            (string) $row['establishment_id'],
            (string) $row['staff_id'],
            (string) $member['person_id'],
            (string) $row['period'],
            (string) $row['contract_id'],
            (int) $row['minutes_total'],
            (int) $row['hetd_milli'],
            (int) $row['gross_amount'],
            array_map(fn (array $hour): PayableHour => $this->hours->payable($tenantId, $hour, $member), $this->lines($tenantId, (string) $row['id'])),
        );
    }

    private function release(string $tenantId, string $statementId): void
    {
        foreach ($this->store->where('vacation_hours', $tenantId, ['statement_id' => $statementId], 'id') as $hour) {
            $this->store->update('vacation_hours', $tenantId, (string) $hour['id'], ['statement_id' => null]);
        }
    }
}
