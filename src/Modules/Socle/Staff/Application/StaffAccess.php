<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoActorDirectory;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoStore;

/**
 * Qui voit et fait quoi. staff.read / staff.manage : dossiers ; vacations.manage : contrats, taux, saisie, relevés, paiement ;
 * vacations.validate : validation des heures, des contrats et des relevés. Sans ces droits, un enseignant ne voit que sa fiche et ses heures.
 */
final class StaffAccess
{
    public const READ = 'staff.read';
    public const MANAGE = 'staff.manage';
    public const VACATIONS_MANAGE = 'vacations.manage';
    public const VACATIONS_VALIDATE = 'vacations.validate';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PdoActorDirectory $actors,
        private readonly PdoStore $store,
    ) {
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId): bool
    {
        return $this->permissions->can($principal, $permission, $establishmentId);
    }

    public function requireManage(Principal $principal, ?string $establishmentId): void
    {
        if (!$this->can($principal, self::MANAGE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de modifier les dossiers du personnel.');
        }
    }

    public function requireVacationsManage(Principal $principal, ?string $establishmentId): void
    {
        if (!$this->can($principal, self::VACATIONS_MANAGE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de gérer les vacations.');
        }
    }

    public function requireValidate(Principal $principal, ?string $establishmentId): void
    {
        if (!$this->can($principal, self::VACATIONS_VALIDATE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de valider les vacations.');
        }
    }

    /** Lecture de tous les dossiers de l'établissement. */
    public function hasFullRead(Principal $principal, ?string $establishmentId): bool
    {
        foreach ([self::READ, self::MANAGE, self::VACATIONS_MANAGE, self::VACATIONS_VALIDATE] as $permission) {
            if ($this->can($principal, $permission, $establishmentId)) {
                return true;
            }
        }

        return false;
    }

    /** Lecture de toutes les vacations (contrats, heures, relevés) de l'établissement. */
    public function hasVacationsRead(Principal $principal, ?string $establishmentId): bool
    {
        foreach ([self::MANAGE, self::VACATIONS_MANAGE, self::VACATIONS_VALIDATE] as $permission) {
            if ($this->can($principal, $permission, $establishmentId)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> fiches du personnel qui sont celles de l'appelant (une au plus) */
    public function ownStaffIds(Principal $principal): array
    {
        $person = $this->actors->personIdOf($principal->tenantId, $principal->userId);
        if ($person === null) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['id'],
            $this->store->where('staff_members', $principal->tenantId, ['person_id' => $person], 'id', 1),
        );
    }

    public function isOwn(Principal $principal, string $staffId): bool
    {
        return in_array($staffId, $this->ownStaffIds($principal), true);
    }
}
