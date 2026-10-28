<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Domain\Permission;
use Scholaris\Modules\Socle\Identity\Domain\Role;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\LazyEstablishmentDirectory;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoAccessTokenRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoPeopleRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleAssignmentRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleCatalog;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Affectation, délégation et retrait de rôles. Les droits de l'acteur sont contrôlés ici (refus par défaut) :
 * rôle de niveau client = permission au niveau client ; rôle d'établissement = permission dans cet établissement.
 */
final class RoleAssignmentService
{
    public const MAX_DELEGATION_DAYS = 90;
    /** Rôles de libre-service : jamais délégués. */
    private const NOT_DELEGABLE = ['parent', 'apprenant'];

    public function __construct(
        private readonly PdoRoleAssignmentRepository $assignments,
        private readonly PdoRoleCatalog $roles,
        private readonly PdoPeopleRepository $people,
        private readonly PdoUserRepository $users,
        private readonly PdoAccessTokenRepository $tokens,
        private readonly PermissionResolver $resolver,
        private readonly LazyEstablishmentDirectory $directory,
        private readonly TerritoryDirectory $territories,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    /**
     * @param array{person_id: string, role: string, scope_type: string, scope_id: ?string, establishment_id: ?string, starts_at: ?string, ends_at: ?string} $input
     */
    public function assign(User $actor, array $input): RoleAssignment
    {
        $role = $this->roles->find($input['role']) ?? throw HttpException::validation(['role' => ['Rôle inconnu.']]);
        self::assertAssignable($role);
        [$scopeType, $scopeId, $establishmentId] = $this->scope($actor->tenantId, $role, $input);
        $this->authorize($actor, $role, $establishmentId);

        if ($this->people->find($actor->tenantId, $input['person_id']) === null) {
            throw HttpException::validation(['person_id' => ['Personne introuvable dans votre client.']]);
        }
        [$startsAt, $endsAt] = $this->dates($input['starts_at'], $input['ends_at']);
        $this->assertDuration($role, $startsAt, $endsAt);

        return $this->store($actor, $input['person_id'], $role, $scopeType, $scopeId, $establishmentId, $startsAt, $endsAt, null);
    }

    /**
     * Délégation temporaire : l'acteur confie à une autre personne un rôle qu'il exerce lui-même, jusqu'à une date de fin.
     *
     * @param array{person_id: string, role: string, scope_type: string, scope_id: ?string, establishment_id: ?string, starts_at: ?string, ends_at: ?string} $input
     */
    public function delegate(User $actor, array $input): RoleAssignment
    {
        $role = $this->roles->find($input['role']) ?? throw HttpException::validation(['role' => ['Rôle inconnu.']]);
        self::assertAssignable($role);
        [$scopeType, $scopeId, $establishmentId] = $this->scope($actor->tenantId, $role, $input);

        if ($input['ends_at'] === null) {
            throw HttpException::validation(['ends_at' => ['Une délégation doit avoir une date de fin.']]);
        }
        if ($input['person_id'] === $actor->personId) {
            throw HttpException::validation(['person_id' => ['Vous ne pouvez pas vous déléguer un rôle à vous-même.']]);
        }
        if ($this->people->find($actor->tenantId, $input['person_id']) === null) {
            throw HttpException::validation(['person_id' => ['Personne introuvable dans votre client.']]);
        }

        $own = null;
        foreach ($this->resolver->activeAssignments($actor->tenantId, $actor->personId) as $assignment) {
            if ($assignment->role === $role->code
                && $assignment->delegatedBy === null
                && $assignment->scopeType === $scopeType
                && $assignment->scopeId === $scopeId
                && $assignment->establishmentId === $establishmentId) {
                $own = $assignment;
                break;
            }
        }
        if ($own === null || in_array($role->code, self::NOT_DELEGABLE, true)) {
            throw HttpException::forbidden('Vous ne pouvez déléguer que les rôles que vous exercez vous-même, dans le même périmètre.');
        }

        [$startsAt, $endsAt] = $this->dates($input['starts_at'], $input['ends_at']);
        $limit = $this->moment->in(self::MAX_DELEGATION_DAYS * 86400);
        if ($endsAt === null || $endsAt > $limit || ($own->endsAt !== null && $endsAt > $own->endsAt)) {
            throw HttpException::validation(['ends_at' => ['La délégation ne peut pas dépasser ' . self::MAX_DELEGATION_DAYS . ' jours ni la fin de votre propre rôle.']]);
        }

        return $this->store($actor, $input['person_id'], $role, $scopeType, $scopeId, $establishmentId, $startsAt, $endsAt, $actor->personId);
    }

    public function revoke(User $actor, string $id): void
    {
        $assignment = $this->assignments->find($actor->tenantId, $id) ?? throw HttpException::notFound('Affectation introuvable.');
        $role = $this->roles->find($assignment->role);

        $ownDelegation = $assignment->delegatedBy !== null && $assignment->delegatedBy === $actor->personId;
        if (!$ownDelegation) {
            if ($role === null) {
                throw HttpException::forbidden();
            }
            $this->authorize($actor, $role, $assignment->establishmentId);
        }
        $this->assignments->delete($actor->tenantId, $id);
        $this->audit->append($actor->tenantId, $actor->id, 'identity.role_revoked', 'role_assignment', $id, self::snapshot($assignment));
    }

    /**
     * @param array{person_id?: string, role?: string, scope_type?: string} $filters
     * @return list<RoleAssignment>
     */
    public function list(User $actor, ?string $contextEstablishment, array $filters, ?string $afterId, int $limit): array
    {
        $tenantWide = $this->resolver->can($actor->tenantId, $actor->personId, Permission::IDENTITY_ROLES_ASSIGN, null)
            || $this->resolver->can($actor->tenantId, $actor->personId, Permission::IDENTITY_MANAGE, null);
        if ($tenantWide) {
            return $this->assignments->search($actor->tenantId, $filters, null, $afterId, $limit);
        }
        $allowed = $contextEstablishment !== null
            && ($this->resolver->can($actor->tenantId, $actor->personId, Permission::IDENTITY_ROLES_ASSIGN, $contextEstablishment)
                || $this->resolver->can($actor->tenantId, $actor->personId, Permission::IDENTITY_MANAGE, $contextEstablishment));
        if (!$allowed) {
            throw HttpException::forbidden();
        }

        return $this->assignments->search($actor->tenantId, $filters, $contextEstablishment, $afterId, $limit);
    }

    /**
     * @param array{scope_type: string, scope_id: ?string, establishment_id: ?string} $input
     * @return array{0: ScopeType, 1: ?string, 2: ?string} type, identifiant de périmètre, établissement rattaché
     */
    private function scope(string $tenantId, Role $role, array $input): array
    {
        $type = ScopeType::tryFrom($input['scope_type']) ?? throw HttpException::validation(['scope_type' => ['Périmètre inconnu (tenant, establishment, territory, org_unit, class ou structure).']]);

        if ($role->isTenantLevel()) {
            if ($type !== ScopeType::Tenant) {
                throw HttpException::validation(['scope_type' => ['Ce rôle est de niveau client : le périmètre doit être « tenant ».']]);
            }

            return [$type, null, null];
        }
        if ($type === ScopeType::Tenant) {
            throw HttpException::validation(['scope_type' => ['Ce rôle s\'exerce dans un établissement : indiquez un périmètre « establishment », « territory », « org_unit », « class » ou « structure ».']]);
        }

        if ($type === ScopeType::Territory) {
            $territoryId = $input['scope_id'];
            if ($territoryId === null || $territoryId === '') {
                throw HttpException::validation(['scope_id' => ['Le territoire est obligatoire pour ce périmètre.']]);
            }
            if (($input['establishment_id'] ?? null) !== null) {
                throw HttpException::validation(['establishment_id' => ['Le périmètre territorial ne porte pas d\'établissement.']]);
            }
            if ($this->territories->find($tenantId, $territoryId, $this->moment->iso()) === null) {
                throw HttpException::validation(['scope_id' => ['Territoire introuvable ou inactif dans votre client.']]);
            }

            return [$type, $territoryId, null];
        }

        $establishmentId = $type === ScopeType::Establishment ? $input['scope_id'] : $input['establishment_id'];
        $scopeId = $input['scope_id'];
        if ($establishmentId === null || $establishmentId === '' || $scopeId === null || $scopeId === '') {
            throw HttpException::validation(['scope_id' => ['Le périmètre (scope_id) et l\'établissement (establishment_id) sont obligatoires.']]);
        }
        if ($this->directory->find($tenantId, $establishmentId) === null) {
            throw HttpException::validation(['establishment_id' => ['Établissement introuvable dans votre client.']]);
        }

        return [$type, $scopeId, $establishmentId];
    }

    /** Le rôle d'administrateur de plateforme ne s'attribue jamais par l'API : seule la commande `platform:admin-create` le crée. */
    /**
     * Contrôle de droit préalable à la lecture du corps (A1) : sans droit d'attribuer un rôle nulle part, 403 avant toute
     * validation (qui révélerait la forme attendue des données). Le contrôle fin par rôle et périmètre suit dans assign().
     */
    public function assertMayAssign(User $actor): void
    {
        if (!$this->resolver->canSomewhere($actor->tenantId, $actor->personId, Permission::IDENTITY_ROLES_ASSIGN)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit d\'attribuer des rôles.');
        }
    }

    /**
     * Contrôle préalable de la délégation (A1) : il faut exercer soi-même, en propre, au moins un rôle délégable. Les rôles
     * de libre-service (parent, apprenant) ne se délèguent pas.
     */
    public function assertMayDelegate(User $actor): void
    {
        foreach ($this->resolver->activeAssignments($actor->tenantId, $actor->personId) as $assignment) {
            if ($assignment->delegatedBy === null && !in_array($assignment->role, self::NOT_DELEGABLE, true)) {
                return;
            }
        }
        throw HttpException::forbidden('Vous n\'exercez aucun rôle que vous puissiez déléguer.');
    }

    /**
     * Rôles que l'acteur peut attribuer dans l'établissement : exactement ceux que assign() accepterait sur le plan du droit
     * (mêmes contrôles), sans rien écrire. Sert à l'import en masse, qui n'élève jamais les droits de l'importeur.
     *
     * @return list<Role>
     */
    public function assignableRoles(User $actor, string $establishmentId): array
    {
        if (!$this->resolver->canSomewhere($actor->tenantId, $actor->personId, Permission::IDENTITY_ROLES_ASSIGN)) {
            return [];
        }
        $result = [];
        foreach ($this->roles->all() as $role) {
            try {
                self::assertAssignable($role);
                $this->authorize($actor, $role, $establishmentId);
            } catch (HttpException) {
                continue;
            }
            $result[] = $role;
        }

        return $result;
    }

    private static function assertAssignable(Role $role): void
    {
        if ($role->code === Role::PLATFORM_ADMIN) {
            throw HttpException::forbidden('Ce rôle est réservé à la plateforme et ne peut pas être attribué.');
        }
    }

    private function authorize(User $actor, Role $role, ?string $establishmentId): void
    {
        if (!$this->resolver->can($actor->tenantId, $actor->personId, Permission::IDENTITY_ROLES_ASSIGN, $role->isTenantLevel() ? null : $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit d\'attribuer ce rôle dans ce périmètre.');
        }
        if ($role->code === Role::SUPER_ADMIN && !$this->resolver->hasRole($actor->tenantId, $actor->personId, Role::SUPER_ADMIN)) {
            throw HttpException::forbidden('Seul un super administrateur peut attribuer ce rôle.');
        }
    }

    /**
     * SS13 : un rôle à durée limitée (comptes externes, `roles.max_assignment_days`) exige une date de fin au plus à cette durée
     * de la date de début. L'affectation échue est ensuite ignorée par la résolution des permissions. Une délégation reste
     * bornée par la fin du rôle délégué.
     */
    private function assertDuration(Role $role, string $startsAt, ?string $endsAt): void
    {
        if ($role->maxAssignmentDays === null) {
            return;
        }
        if ($endsAt === null || $this->moment->secondsBetween($startsAt, $endsAt) > $role->maxAssignmentDays * 86400) {
            throw HttpException::validation(['ends_at' => ['Ce rôle s\'attribue pour une durée limitée : indiquez une date de fin au plus ' . $role->maxAssignmentDays . ' jours après la date de début.']]);
        }
    }

    /** @return array{0: string, 1: ?string} */
    private function dates(?string $startsAt, ?string $endsAt): array
    {
        $errors = [];
        $start = $this->moment->iso();
        if ($startsAt !== null) {
            $normalized = $this->moment->normalize($startsAt);
            if ($normalized === null) {
                $errors['starts_at'] = ['Date de début invalide (format ISO 8601).'];
            } else {
                $start = $normalized;
            }
        }
        $end = null;
        if ($endsAt !== null) {
            $end = $this->moment->normalize($endsAt);
            if ($end === null) {
                $errors['ends_at'] = ['Date de fin invalide (format ISO 8601).'];
            } elseif ($end <= $start) {
                $errors['ends_at'] = ['La date de fin doit être postérieure à la date de début.'];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [$start, $end];
    }

    private function store(
        User $actor,
        string $personId,
        Role $role,
        ScopeType $scopeType,
        ?string $scopeId,
        ?string $establishmentId,
        string $startsAt,
        ?string $endsAt,
        ?string $delegatedBy,
    ): RoleAssignment {
        $assignment = new RoleAssignment(
            (string) new Ulid(),
            $actor->tenantId,
            $personId,
            $role->code,
            $scopeType,
            $scopeId,
            $establishmentId,
            $startsAt,
            $endsAt,
            $delegatedBy,
            $actor->id,
            $this->moment->iso(),
        );
        $this->assignments->insert($assignment);

        // Un rôle sensible attribué après la connexion impose la double authentification : les sessions qui n'en ont jamais passé sont fermées.
        if ($role->sensitive) {
            $target = $this->users->findByPerson($actor->tenantId, $personId);
            if ($target !== null) {
                $this->tokens->revokeWithoutTwoFactor($actor->tenantId, $target->id, $this->moment->iso());
            }
        }
        $this->audit->append(
            $actor->tenantId,
            $actor->id,
            $delegatedBy === null ? 'identity.role_assigned' : 'identity.role_delegated',
            'role_assignment',
            $assignment->id,
            null,
            self::snapshot($assignment),
        );

        return $assignment;
    }

    /** @return array<string, mixed> */
    private static function snapshot(RoleAssignment $a): array
    {
        return [
            'person_id' => $a->personId,
            'role' => $a->role,
            'scope_type' => $a->scopeType->value,
            'scope_id' => $a->scopeId,
            'establishment_id' => $a->establishmentId,
            'starts_at' => $a->startsAt,
            'ends_at' => $a->endsAt,
            'delegated_by' => $a->delegatedBy,
        ];
    }
}
