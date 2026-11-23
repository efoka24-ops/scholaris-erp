<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMoveInfo;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMover;
use Scholaris\Modules\Socle\Staff\Domain\StaffStatus;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoStore;

final class StaffMoverService implements StaffMover
{
    public function __construct(
        private readonly PdoStore $store,
        private readonly StaffClock $clock,
        private readonly StaffService $staff,
        private readonly EstablishmentDirectory $establishments,
    ) {
    }

    public function move(string $tenantId, string $staffId, string $toEstablishmentId, string $effectiveOn, ?string $reason, string $actorId): StaffMoveInfo
    {
        $member = $this->staff->inTenant($tenantId, $staffId);
        if ($member['status'] === StaffStatus::Left->value) {
            throw HttpException::conflict('Ce membre du personnel a quitté l\'établissement.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveOn);
        if ($date === false || $date->format('Y-m-d') !== $effectiveOn) {
            throw HttpException::validation(['effective_on' => ['Date AAAA-MM-JJ attendue.']]);
        }
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && mb_strlen($reason) > 500) {
            throw HttpException::validation(['reason' => ['500 caractères au plus.']]);
        }
        $from = (string) $member['home_establishment_id'];
        if ($from === $toEstablishmentId) {
            throw HttpException::validation(['to_establishment_id' => ['Le membre est déjà rattaché à cet établissement.']]);
        }
        if ($this->establishments->find($tenantId, $toEstablishmentId) === null) {
            throw HttpException::notFound('Établissement introuvable.');
        }

        return $this->store->transaction(function () use ($tenantId, $staffId, $from, $toEstablishmentId, $effectiveOn, $reason, $actorId): StaffMoveInfo {
            $now = $this->clock->now();
            $closed = $this->store->pdo()->prepare(
                "UPDATE staff_assignments SET status = 'ended', ends_on = CASE WHEN ends_on IS NULL OR ends_on > ? THEN ? ELSE ends_on END, updated_at = ?
                 WHERE tenant_id = ? AND staff_id = ? AND establishment_id = ? AND status = 'active'",
            );
            $closed->execute([$effectiveOn, $effectiveOn, $now, $tenantId, $staffId, $from]);
            $ended = $closed->rowCount();
            $this->store->update('staff_members', $tenantId, $staffId, ['home_establishment_id' => $toEstablishmentId, 'updated_at' => $now]);
            $id = (string) new Ulid();
            $this->store->insert('staff_moves', [
                'id' => $id, 'tenant_id' => $tenantId, 'staff_id' => $staffId, 'from_establishment_id' => $from,
                'to_establishment_id' => $toEstablishmentId, 'effective_on' => $effectiveOn, 'reason' => $reason === '' ? null : $reason,
                'ended_assignments' => $ended, 'created_by' => $actorId, 'created_at' => $now,
            ]);

            return new StaffMoveInfo($id, $staffId, $from, $toEstablishmentId, $effectiveOn, $reason === '' ? null : $reason, $ended);
        });
    }

    public function history(string $tenantId, string $staffId): array
    {
        $rows = $this->store->rows(
            'SELECT * FROM staff_moves WHERE tenant_id = ? AND staff_id = ? ORDER BY effective_on, created_at, id',
            [$tenantId, $staffId],
        );

        return array_map(static fn (array $r): StaffMoveInfo => new StaffMoveInfo(
            (string) $r['id'],
            (string) $r['staff_id'],
            (string) $r['from_establishment_id'],
            (string) $r['to_establishment_id'],
            (string) $r['effective_on'],
            $r['reason'] === null ? null : (string) $r['reason'],
            (int) $r['ended_assignments'],
        ), $rows);
    }
}
