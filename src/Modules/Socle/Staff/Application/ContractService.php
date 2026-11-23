<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Numbering\SequenceService;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Staff\Domain\StaffKind;
use Scholaris\Modules\Socle\Staff\Domain\StaffStatus;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoStore;

/**
 * Contrats de vacation. Un contrat fixe un taux (ou renvoie à celui de la catégorie) et un plafond d'heures : c'est un engagement
 * financier, donc son activation passe par une validation à deux personnes (Approvals).
 * États : draft, pending_approval, active, rejected, terminated.
 */
final class ContractService
{
    public const KIND = 'vacation.contract';

    public function __construct(
        private readonly PdoStore $store,
        private readonly StaffClock $clock,
        private readonly StaffService $staff,
        private readonly RateService $rates,
        private readonly Approvals $approvals,
        private readonly SequenceService $sequences,
    ) {
    }

    /**
     * @param array<string, mixed> $data staff_id, starts_on, ends_on, hourly_rate, max_minutes
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $establishmentId, array $data, string $actorId): array
    {
        $member = $this->staff->get($tenantId, $establishmentId, (string) $data['staff_id']);
        if ($member['kind'] === StaffKind::Permanent->value) {
            throw HttpException::conflict('Un enseignant permanent n\'a pas de contrat de vacation.');
        }
        if ($member['status'] === StaffStatus::Left->value) {
            throw HttpException::conflict('Ce membre du personnel a quitté l\'établissement.');
        }
        $this->checkPeriod((string) $data['starts_on'], (string) $data['ends_on']);
        $this->assertNoOverlap($tenantId, $establishmentId, (string) $data['staff_id'], (string) $data['starts_on'], (string) $data['ends_on'], null);

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $year = (int) substr((string) $data['starts_on'], 0, 4);
        $this->sequences->transaction(function () use ($tenantId, $establishmentId, $data, $actorId, $id, $now, $year): void {
            $reference = SequenceService::format('VAC', $year, $this->sequences->next($tenantId, $establishmentId, 'vacation_contract', $year), 5);
            $this->store->insert('vacation_contracts', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'staff_id' => $data['staff_id'],
                'reference' => $reference, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
                'hourly_rate' => $data['hourly_rate'] ?? null, 'max_minutes' => $data['max_minutes'] ?? null,
                'status' => 'draft', 'approval_id' => null, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        });

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $changes starts_on, ends_on, hourly_rate, max_minutes
     * @return array<string, mixed>
     */
    public function update(string $tenantId, string $establishmentId, string $id, array $changes): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if (!in_array($row['status'], ['draft', 'rejected'], true)) {
            throw HttpException::conflict('Seul un contrat en brouillon ou refusé peut être modifié.');
        }
        $start = (string) ($changes['starts_on'] ?? $row['starts_on']);
        $end = (string) ($changes['ends_on'] ?? $row['ends_on']);
        $this->checkPeriod($start, $end);
        $this->assertNoOverlap($tenantId, $establishmentId, (string) $row['staff_id'], $start, $end, $id);
        $changes['updated_at'] = $this->clock->now();
        $this->store->update('vacation_contracts', $tenantId, $id, $changes);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->store->find('vacation_contracts', $tenantId, $id);
        if ($row === null || $row['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Contrat de vacation introuvable.');
        }

        return $row;
    }

    /**
     * Demande la validation : le taux doit être connu (propre au contrat ou de la catégorie).
     *
     * @return array<string, mixed>
     */
    public function submit(string $tenantId, string $establishmentId, string $id, string $actorId): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if (!in_array($row['status'], ['draft', 'rejected'], true)) {
            throw HttpException::conflict('Ce contrat a déjà été soumis.');
        }
        $member = $this->staff->get($tenantId, $establishmentId, (string) $row['staff_id']);
        if ($this->rates->hourlyRate($tenantId, $establishmentId, $row, $member) === null) {
            throw HttpException::validation(['hourly_rate' => ['Aucun taux : renseignez le taux du contrat ou celui de la catégorie de l\'enseignant.']]);
        }
        $approvalId = $this->approvals->request($tenantId, self::KIND, 'vacation_contract', $id, 'Contrat de vacation ' . $row['reference'], $actorId);
        $this->store->update('vacation_contracts', $tenantId, $id, ['status' => 'pending_approval', 'approval_id' => $approvalId, 'updated_at' => $this->clock->now()]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function approve(string $tenantId, string $establishmentId, string $id, string $approverId): array
    {
        $row = $this->pending($tenantId, $establishmentId, $id);
        $this->approvals->approve($tenantId, (string) $row['approval_id'], $approverId);
        $this->store->update('vacation_contracts', $tenantId, $id, ['status' => 'active', 'updated_at' => $this->clock->now()]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function reject(string $tenantId, string $establishmentId, string $id, string $approverId): array
    {
        $row = $this->pending($tenantId, $establishmentId, $id);
        $this->approvals->reject($tenantId, (string) $row['approval_id'], $approverId);
        $this->store->update('vacation_contracts', $tenantId, $id, ['status' => 'rejected', 'updated_at' => $this->clock->now()]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function terminate(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== 'active') {
            throw HttpException::conflict('Seul un contrat actif peut être résilié.');
        }
        $this->store->update('vacation_contracts', $tenantId, $id, ['status' => 'terminated', 'updated_at' => $this->clock->now()]);

        return $this->get($tenantId, $establishmentId, $id);
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
        foreach (['staff_id', 'status'] as $field) {
            if (isset($query->filters[$field])) {
                $where[] = $field . ' = ?';
                $params[] = $query->filters[$field];
            }
        }
        if ($restrictTo !== null) {
            $where[] = 'staff_id IN (' . implode(', ', array_fill(0, count($restrictTo), '?')) . ')';
            array_push($params, ...$restrictTo);
        }
        $rows = $this->store->rows(sprintf('SELECT * FROM vacation_contracts WHERE %s ORDER BY id LIMIT %d', implode(' AND ', $where), $query->perPage + 1), $params);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return [$rows, $next];
    }

    /** @return array<string, mixed>|null contrat actif couvrant cette date */
    public function activeOn(string $tenantId, string $establishmentId, string $staffId, string $date): ?array
    {
        $rows = $this->store->rows(
            'SELECT * FROM vacation_contracts WHERE tenant_id = ? AND establishment_id = ? AND staff_id = ? AND status = ? AND starts_on <= ? AND ends_on >= ? ORDER BY id LIMIT 1',
            [$tenantId, $establishmentId, $staffId, 'active', $date, $date],
        );

        return $rows[0] ?? null;
    }

    /** @return array<string, mixed> */
    private function pending(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== 'pending_approval' || $row['approval_id'] === null) {
            throw HttpException::conflict('Ce contrat n\'attend pas de validation.');
        }

        return $row;
    }

    private function checkPeriod(string $start, string $end): void
    {
        if ($end < $start) {
            throw HttpException::validation(['ends_on' => ['La date de fin doit suivre la date de début.']]);
        }
    }

    private function assertNoOverlap(string $tenantId, string $establishmentId, string $staffId, string $start, string $end, ?string $exceptId): void
    {
        $rows = $this->store->rows(
            'SELECT id FROM vacation_contracts WHERE tenant_id = ? AND establishment_id = ? AND staff_id = ? AND status IN (?, ?, ?) AND starts_on <= ? AND ends_on >= ?',
            [$tenantId, $establishmentId, $staffId, 'draft', 'pending_approval', 'active', $end, $start],
        );
        foreach ($rows as $row) {
            if ($row['id'] !== $exceptId) {
                throw HttpException::conflict('Un autre contrat de vacation couvre déjà cette période pour cet enseignant.');
            }
        }
    }
}
