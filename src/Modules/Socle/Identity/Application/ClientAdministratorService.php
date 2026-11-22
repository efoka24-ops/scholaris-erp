<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Contracts\ClientAdministrators;
use Scholaris\Modules\Socle\Identity\Domain\Role;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Identity\Infrastructure\LazyEstablishmentDirectory;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleAssignmentRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleCatalog;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/**
 * Premier administrateur d'un client : compte en attente d'activation par code + rôle `super_admin` de niveau client.
 * Rôles de direction des applications du client donnés à ses administrateurs (IPES-ADMIN) : voir le contrat.
 */
final class ClientAdministratorService implements ClientAdministrators
{
    /** Plafond de lecture des affectations super_admin d'un client (quelques administrateurs en pratique). */
    private const MAX_ADMINISTRATORS = 500;

    public function __construct(
        private readonly AccountProvisioner $provisioner,
        private readonly PdoUserRepository $users,
        private readonly PdoRoleAssignmentRepository $assignments,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
        private readonly PdoRoleCatalog $roles,
        private readonly LazyEstablishmentDirectory $establishments,
    ) {
    }

    public function invite(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email, string $locale, string $grantedBy): array
    {
        if ($tenantId === '' || $tenantId === Role::PLATFORM_TENANT) {
            throw HttpException::validation(['tenant_id' => ['Client invalide.']]);
        }
        $ids = $this->provisioner->provision($tenantId, $firstName, $lastName, $phone, $email, $locale);
        $user = $this->users->find($tenantId, $ids['user_id']);
        $now = $this->moment->iso();
        $this->assignments->insert(new RoleAssignment(
            (string) new Ulid(),
            $tenantId,
            $ids['person_id'],
            Role::SUPER_ADMIN,
            ScopeType::Tenant,
            $tenantId,
            null,
            $now,
            null,
            null,
            $grantedBy,
            $now,
        ));
        $this->audit->append($tenantId, null, 'identity.role_assigned', 'role_assignment', $ids['person_id'], null, ['role' => Role::SUPER_ADMIN, 'person_id' => $ids['person_id'], 'granted_by' => $grantedBy]);

        return $ids + ['login' => (string) ($user->phone ?? $user->email ?? '')];
    }

    public function grantApplicationRoles(string $tenantId, array $roles, string $permissionPrefix, array $establishmentIds, string $grantedBy): array
    {
        if ($tenantId === '' || $tenantId === Role::PLATFORM_TENANT || $permissionPrefix === '') {
            return [];
        }
        $catalog = $this->roles->all();
        $grantable = [];
        foreach ($roles as $code) {
            if (isset($catalog[$code]) && $code !== Role::SUPER_ADMIN && !in_array($code, Role::PLATFORM_ROLES, true)) {
                $grantable[$code] = $catalog[$code];
            }
        }
        $establishments = array_values(array_filter(
            array_unique($establishmentIds),
            fn (string $id): bool => $this->establishments->find($tenantId, $id) !== null,
        ));
        if ($grantable === [] || $establishments === []) {
            return [];
        }

        $now = $this->moment->iso();
        $created = [];
        foreach ($this->administrators($tenantId, $now) as $personId) {
            $active = $this->assignments->activeForPerson($tenantId, $personId, $now);
            $permissions = $this->roles->permissionsByRole(array_values(array_unique(array_map(static fn (RoleAssignment $a): string => $a->role, $active))));
            foreach ($establishments as $establishmentId) {
                if (self::holdsApplicationRole($active, $permissions, $establishmentId, $permissionPrefix)) {
                    continue;
                }
                foreach ($grantable as $code => $role) {
                    $tenantLevel = $role->isTenantLevel();
                    if ($tenantLevel && self::holds($active, $code, null)) {
                        continue;
                    }
                    $assignment = new RoleAssignment(
                        (string) new Ulid(),
                        $tenantId,
                        $personId,
                        $code,
                        $tenantLevel ? ScopeType::Tenant : ScopeType::Establishment,
                        $tenantLevel ? $tenantId : $establishmentId,
                        $tenantLevel ? null : $establishmentId,
                        $now,
                        null,
                        null,
                        $grantedBy,
                        $now,
                    );
                    $this->assignments->insert($assignment);
                    $active[] = $assignment;
                    $this->audit->append($tenantId, null, 'identity.role_assigned', 'role_assignment', $personId, null, [
                        'role' => $code, 'person_id' => $personId, 'establishment_id' => $assignment->establishmentId, 'granted_by' => $grantedBy, 'reason' => 'application_administrator',
                    ]);
                    $created[] = ['person_id' => $personId, 'role' => $code, 'establishment_id' => $assignment->establishmentId];
                }
            }
        }

        return $created;
    }

    /** @return list<string> personnes ayant une affectation `super_admin` de niveau client en vigueur */
    private function administrators(string $tenantId, string $now): array
    {
        $people = [];
        foreach ($this->assignments->search($tenantId, ['role' => Role::SUPER_ADMIN, 'scope_type' => ScopeType::Tenant->value], null, null, self::MAX_ADMINISTRATORS) as $assignment) {
            if ($assignment->isActiveAt($now)) {
                $people[$assignment->personId] = $assignment->personId;
            }
        }

        return array_values($people);
    }

    /**
     * Rôle de l'application déjà exercé dans l'établissement : un rôle autre que super_admin qui porte une permission de l'application
     * (hors simple accès `<préfixe>access`).
     *
     * @param list<RoleAssignment> $active
     * @param array<string, list<string>> $permissions
     */
    private static function holdsApplicationRole(array $active, array $permissions, string $establishmentId, string $prefix): bool
    {
        foreach ($active as $assignment) {
            if ($assignment->role === Role::SUPER_ADMIN || !$assignment->appliesTo($establishmentId)) {
                continue;
            }
            foreach ($permissions[$assignment->role] ?? [] as $permission) {
                if (str_starts_with($permission, $prefix) && $permission !== $prefix . 'access') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<RoleAssignment> $active */
    private static function holds(array $active, string $role, ?string $establishmentId): bool
    {
        foreach ($active as $assignment) {
            if ($assignment->role === $role && $assignment->appliesTo($establishmentId)) {
                return true;
            }
        }

        return false;
    }
}
