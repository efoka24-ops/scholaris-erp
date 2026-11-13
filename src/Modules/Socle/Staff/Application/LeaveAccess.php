<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;

/**
 * Qui fait quoi pour les congés. staff.leave.request : demander et annuler pour soi-même ; staff.leave.approve : valider ou refuser
 * et voir toutes les demandes ; staff.manage : fixer les soldes et voir toutes les demandes.
 */
final class LeaveAccess
{
    public const REQUEST = 'staff.leave.request';
    public const APPROVE = 'staff.leave.approve';

    public function __construct(private readonly StaffAccess $access)
    {
    }

    public function requireRequest(Principal $principal, string $establishmentId): void
    {
        if (!$this->access->can($principal, self::REQUEST, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de demander un congé.');
        }
    }

    public function requireApprove(Principal $principal, string $establishmentId): void
    {
        if (!$this->access->can($principal, self::APPROVE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de valider les congés.');
        }
    }

    public function requireManage(Principal $principal, string $establishmentId): void
    {
        $this->access->requireManage($principal, $establishmentId);
    }

    public function canSeeAll(Principal $principal, string $establishmentId): bool
    {
        return $this->access->can($principal, self::APPROVE, $establishmentId) || $this->access->can($principal, StaffAccess::MANAGE, $establishmentId);
    }

    /** @return list<string>|null fiches visibles ; null = tout l'établissement */
    public function scope(Principal $principal, string $establishmentId): ?array
    {
        return $this->canSeeAll($principal, $establishmentId) ? null : $this->access->ownStaffIds($principal);
    }

    public function isOwn(Principal $principal, string $staffId): bool
    {
        return $this->access->isOwn($principal, $staffId);
    }

    /** @return list<string> */
    public function ownStaffIds(Principal $principal): array
    {
        return $this->access->ownStaffIds($principal);
    }
}
