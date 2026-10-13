<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Staff\Domain\LeaveType;
use Scholaris\Modules\Socle\Staff\Domain\StaffStatus;
use Scholaris\Modules\Socle\Staff\Infrastructure\LeaveRepository;

/**
 * Congés et absences. Une demande naît « pending » avec une demande d'Approvals (le demandeur ne peut pas la valider), puis devient
 * « approved », « rejected » ou « cancelled ». Les jours comptés sont les jours ouvrés (lundi au vendredi), imputés sur l'année de début.
 * Un solde n'existe que si un droit annuel a été fixé pour le type : sans droit fixé, le type n'est pas plafonné.
 */
final class LeaveService
{
    public const KIND = 'staff.leave';

    public function __construct(
        private readonly LeaveRepository $repository,
        private readonly StaffClock $clock,
        private readonly StaffService $staff,
        private readonly Approvals $approvals,
    ) {
    }

    /**
     * @param array<string, mixed> $data leave_type, starts_on, ends_on, reason
     * @return array<string, mixed>
     */
    public function request(string $tenantId, string $establishmentId, string $staffId, array $data, string $actorId): array
    {
        $member = $this->staff->get($tenantId, $establishmentId, $staffId);
        if ($member['status'] === StaffStatus::Left->value) {
            throw HttpException::conflict('Ce membre du personnel a quitté l\'établissement.');
        }
        $start = (string) $data['starts_on'];
        $end = (string) $data['ends_on'];
        if ($end < $start) {
            throw HttpException::validation(['ends_on' => ['La date de fin doit suivre la date de début.']]);
        }
        $days = self::workingDays($start, $end);
        if ($days === 0) {
            throw HttpException::validation(['starts_on' => ['La période ne contient aucun jour ouvré.']]);
        }
        $type = (string) $data['leave_type'];
        $this->assertNoOverlap($tenantId, $establishmentId, $staffId, $start, $end);
        $this->assertWithinBalance($tenantId, $staffId, $type, (int) substr($start, 0, 4), $days, 0);

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->repository->insertRequest([
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'staff_id' => $staffId,
            'leave_type' => $type, 'starts_on' => $start, 'ends_on' => $end, 'days' => $days,
            'reason' => $data['reason'] ?? null, 'status' => 'pending', 'approval_id' => null, 'requested_by' => $actorId,
            'decided_by' => null, 'decided_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $approvalId = $this->approvals->request($tenantId, self::KIND, 'leave_request', $id, sprintf('Congé %s du %s au %s', $type, $start, $end), $actorId);
        $this->repository->attachApproval($tenantId, $establishmentId, $id, $approvalId);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->repository->findRequest($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Demande de congé introuvable.');
    }

    /** @return array<string, mixed> */
    public function approve(string $tenantId, string $establishmentId, string $id, string $approverId): array
    {
        $row = $this->pending($tenantId, $establishmentId, $id);
        $this->assertWithinBalance($tenantId, (string) $row['staff_id'], (string) $row['leave_type'], (int) substr((string) $row['starts_on'], 0, 4), (int) $row['days'], (int) $row['days']);
        $this->approvals->approve($tenantId, (string) $row['approval_id'], $approverId);
        $this->decide($tenantId, $establishmentId, $id, 'approved', $approverId);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function reject(string $tenantId, string $establishmentId, string $id, string $approverId): array
    {
        $row = $this->pending($tenantId, $establishmentId, $id);
        $this->approvals->reject($tenantId, (string) $row['approval_id'], $approverId);
        $this->decide($tenantId, $establishmentId, $id, 'rejected', $approverId);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * Annulation par le demandeur : possible tant que la demande est en attente, ou approuvée et pas encore commencée.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        $cancellable = $row['status'] === 'pending' || ($row['status'] === 'approved' && (string) $row['starts_on'] > $this->clock->today());
        if (!$cancellable) {
            throw HttpException::conflict('Cette demande ne peut plus être annulée.');
        }
        $this->repository->updateStatus($tenantId, $establishmentId, $id, 'cancelled', null, null, $this->clock->now());

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
        foreach (['staff_id', 'status', 'leave_type'] as $field) {
            if (isset($query->filters[$field])) {
                $where[] = $field . ' = ?';
                $params[] = $query->filters[$field];
            }
        }
        if ($restrictTo !== null) {
            $where[] = 'staff_id IN (' . implode(', ', array_fill(0, count($restrictTo), '?')) . ')';
            array_push($params, ...$restrictTo);
        }
        $rows = $this->repository->rows(sprintf('SELECT * FROM leave_requests WHERE %s ORDER BY id LIMIT %d', implode(' AND ', $where), $query->perPage + 1), $params);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return [$rows, $next];
    }

    /**
     * Calendrier d'absence : demandes en attente ou approuvées qui touchent la période.
     *
     * @param list<string>|null $restrictTo
     * @return list<array<string, mixed>>
     */
    public function calendar(string $tenantId, string $establishmentId, string $from, string $to, ?array $restrictTo): array
    {
        if ($restrictTo === []) {
            return [];
        }
        $where = ['tenant_id = ?', 'establishment_id = ?', 'status IN (?, ?)', 'starts_on <= ?', 'ends_on >= ?'];
        $params = [$tenantId, $establishmentId, 'pending', 'approved', $to, $from];
        if ($restrictTo !== null) {
            $where[] = 'staff_id IN (' . implode(', ', array_fill(0, count($restrictTo), '?')) . ')';
            array_push($params, ...$restrictTo);
        }

        return $this->repository->rows(sprintf('SELECT * FROM leave_requests WHERE %s ORDER BY starts_on, id LIMIT 1000', implode(' AND ', $where)), $params);
    }

    /**
     * Soldes d'une fiche pour une année : un élément par type ayant un droit fixé ou des jours pris/en attente.
     *
     * @return list<array<string, mixed>>
     */
    public function balances(string $tenantId, string $establishmentId, string $staffId, int $year): array
    {
        $this->staff->get($tenantId, $establishmentId, $staffId);
        $out = [];
        foreach (LeaveType::values() as $type) {
            $balance = $this->repository->findBalance($tenantId, $staffId, $type, $year);
            $used = $this->sumDays($tenantId, $staffId, $type, $year, 'approved');
            $pending = $this->sumDays($tenantId, $staffId, $type, $year, 'pending');
            if ($balance === null && $used === 0 && $pending === 0) {
                continue;
            }
            $entitled = $balance === null ? null : (int) $balance['entitled_days'];
            $out[] = [
                'staff_id' => $staffId, 'leave_type' => $type, 'leave_year' => $year, 'entitled_days' => $entitled,
                'used_days' => $used, 'pending_days' => $pending, 'remaining_days' => $entitled === null ? null : $entitled - $used - $pending,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function setBalance(string $tenantId, string $establishmentId, string $staffId, string $type, int $year, int $entitled): array
    {
        $this->staff->get($tenantId, $establishmentId, $staffId);
        $this->repository->upsertBalance((string) new Ulid(), $tenantId, $establishmentId, $staffId, $type, $year, $entitled, $this->clock->now());

        return $this->balances($tenantId, $establishmentId, $staffId, $year);
    }

    /** Nombre de jours ouvrés (lundi au vendredi) entre deux dates incluses. */
    public static function workingDays(string $start, string $end): int
    {
        $period = new DatePeriod(new DateTimeImmutable($start), new DateInterval('P1D'), (new DateTimeImmutable($end))->modify('+1 day'));
        $days = 0;
        foreach ($period as $day) {
            if ((int) $day->format('N') <= 5) {
                ++$days;
            }
        }

        return $days;
    }

    /** @return array<string, mixed> */
    private function pending(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if ($row['status'] !== 'pending' || $row['approval_id'] === null) {
            throw HttpException::conflict('Cette demande n\'attend pas de décision.');
        }

        return $row;
    }

    private function decide(string $tenantId, string $establishmentId, string $id, string $status, string $approverId): void
    {
        $now = $this->clock->now();
        $this->repository->updateStatus($tenantId, $establishmentId, $id, $status, $approverId, $now, $now);
    }

    private function sumDays(string $tenantId, string $staffId, string $type, int $year, string $status): int
    {
        $rows = $this->repository->rows(
            'SELECT COALESCE(SUM(days), 0) AS total FROM leave_requests WHERE tenant_id = ? AND staff_id = ? AND leave_type = ? AND status = ? AND starts_on >= ? AND starts_on <= ?',
            [$tenantId, $staffId, $type, $status, $year . '-01-01', $year . '-12-31'],
        );

        return (int) ($rows[0]['total'] ?? 0);
    }

    /** @param int $ownDays jours de la demande déjà comptés dans « en attente » (à l'approbation) */
    private function assertWithinBalance(string $tenantId, string $staffId, string $type, int $year, int $days, int $ownDays): void
    {
        $balance = $this->repository->findBalance($tenantId, $staffId, $type, $year);
        if ($balance === null) {
            return;
        }
        $committed = $this->sumDays($tenantId, $staffId, $type, $year, 'approved') + $this->sumDays($tenantId, $staffId, $type, $year, 'pending') - $ownDays;
        $remaining = (int) $balance['entitled_days'] - $committed;
        if ($days > $remaining) {
            throw HttpException::conflict(sprintf('Solde insuffisant : %d jour(s) restant(s) pour ce type de congé en %d.', max(0, $remaining), $year));
        }
    }

    private function assertNoOverlap(string $tenantId, string $establishmentId, string $staffId, string $start, string $end): void
    {
        $rows = $this->repository->rows(
            'SELECT id FROM leave_requests WHERE tenant_id = ? AND establishment_id = ? AND staff_id = ? AND status IN (?, ?) AND starts_on <= ? AND ends_on >= ? LIMIT 1',
            [$tenantId, $establishmentId, $staffId, 'pending', 'approved', $end, $start],
        );
        if ($rows !== []) {
            throw HttpException::conflict('Une autre demande de congé couvre déjà cette période pour ce membre du personnel.');
        }
    }
}
