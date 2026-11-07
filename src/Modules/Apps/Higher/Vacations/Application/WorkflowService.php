<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Vacations\Domain\VacationStatus;
use Scholaris\Modules\Apps\Higher\Vacations\Infrastructure\VacationStore;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Validation et paiement d'un état (EX-ENS-06/07, decisions.md C12) : visa de l'école (R04), approbation de la direction (R02, désactivable
 * par `higher.vacation.direction_approval`), paiement par le comptable (R15) par virement ou mobile money, NOT-14. L'approbation et le
 * paiement sont refusés tant qu'une condition d'état du socle retient le solde (RG-ENS-5 : notes de l'EC incomplètes).
 */
final class WorkflowService
{
    public function __construct(
        private readonly VacationStore $store,
        private readonly VacationAccess $access,
        private readonly HoldEvaluator $holds,
        private readonly VacationNotices $notices,
        private readonly SettingsReader $settings,
        private readonly VacationClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function validateSchool(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->writable($principal, $establishmentId, $id, VacationPermissions::VALIDATE_SCHOOL, [VacationStatus::DRAFT]);
        $direction = $this->settings->value($principal->tenantId, $establishmentId, VacationPermissions::DIRECTION_APPROVAL, true) !== false;
        $now = $this->clock->now();
        $changes = ['status' => VacationStatus::SCHOOL_VALIDATED, 'school_validated_by' => $principal->userId, 'school_validated_at' => $now];
        if (!$direction) {
            $this->assertNoHold($row, $principal);
            $changes = ['status' => VacationStatus::APPROVED, 'approved_by' => $principal->userId, 'approved_at' => $now] + $changes;
        }

        return $this->apply($principal, $establishmentId, $row, $changes, 'higher.vacation.school_validated');
    }

    /** @return array<string, mixed> */
    public function approve(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->writable($principal, $establishmentId, $id, VacationPermissions::APPROVE, [VacationStatus::SCHOOL_VALIDATED]);
        $this->assertNoHold($row, $principal);

        return $this->apply($principal, $establishmentId, $row, ['status' => VacationStatus::APPROVED, 'approved_by' => $principal->userId, 'approved_at' => $this->clock->now()], 'higher.vacation.approved');
    }

    /** @return array<string, mixed> */
    public function reject(Principal $principal, string $establishmentId, string $id, string $reason): array
    {
        $row = $this->writable($principal, $establishmentId, $id, VacationPermissions::REJECT, [VacationStatus::DRAFT, VacationStatus::SCHOOL_VALIDATED, VacationStatus::APPROVED]);

        return $this->apply($principal, $establishmentId, $row, ['status' => VacationStatus::REJECTED, 'rejected_by' => $principal->userId, 'rejected_at' => $this->clock->now(), 'rejection_reason' => $reason], 'higher.vacation.rejected');
    }

    /** @return array<string, mixed> */
    public function pay(Principal $principal, string $establishmentId, string $id, string $method, string $reference, ?string $paidOn): array
    {
        $row = $this->writable($principal, $establishmentId, $id, VacationPermissions::PAY, [VacationStatus::APPROVED]);
        $this->assertNoHold($row, $principal);
        $paid = $this->apply($principal, $establishmentId, $row, [
            'status' => VacationStatus::PAID, 'payment_method' => $method, 'payment_reference' => $reference,
            'paid_on' => $paidOn ?? $this->clock->today(), 'paid_by' => $principal->userId, 'paid_at' => $this->clock->now(),
        ], 'higher.vacation.paid');
        $this->notices->paid($paid);

        return $paid;
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $from
     * @return array<string, mixed>
     */
    public function writable(Principal $principal, string $establishmentId, string $id, array $permissions, array $from): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $row = $this->store->find(StatementService::TABLE, $principal->tenantId, $establishmentId, $id) ?? throw HttpException::notFound('État introuvable.');
        $this->access->assertWritable($principal, $perimeter, $row, $id);
        if (!in_array($row['status'], $from, true)) {
            throw HttpException::conflict(sprintf('Opération impossible sur un état au statut « %s ».', (string) $row['status']));
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function assertNoHold(array $row, Principal $principal): void
    {
        $reasons = $this->holds->refresh($row, $principal->userId);
        if ($reasons !== []) {
            throw HttpException::conflict('Solde retenu : ' . implode(' ', $reasons));
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, scalar|null> $changes
     * @return array<string, mixed>
     */
    private function apply(Principal $principal, string $establishmentId, array $row, array $changes, string $action): array
    {
        $id = (string) $row['id'];
        $this->store->update(StatementService::TABLE, $principal->tenantId, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => $id, 'status' => (string) $row['status']]);
        $this->access->audit($principal->tenantId, $principal->userId, $action, $id, ['status' => $row['status']], $changes);

        return $this->store->find(StatementService::TABLE, $principal->tenantId, $establishmentId, $id) ?? [];
    }
}
