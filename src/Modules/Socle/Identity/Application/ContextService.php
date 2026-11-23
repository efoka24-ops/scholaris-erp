<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Contracts\TileIndicators;
use Scholaris\Modules\Socle\Identity\Domain\AccessToken;
use Scholaris\Modules\Socle\Identity\Domain\Role;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\LazyCurrentPeriod;
use Scholaris\Modules\Socle\Identity\Infrastructure\LazyEstablishmentDirectory;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoAccessTokenRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleCatalog;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Lanceur « Mes établissements » : établissements accessibles à la personne (annuaire de l'Organisation) avec ses rôles,
 * tuile « Tout le groupe » pour la direction de groupe seulement, et changement de contexte sans reconnexion.
 */
final class ContextService
{
    public const GROUP_ID = 'group';

    public function __construct(
        private readonly PermissionResolver $resolver,
        private readonly PdoRoleCatalog $roles,
        private readonly LazyEstablishmentDirectory $directory,
        private readonly TerritoryDirectory $territories,
        private readonly PdoAccessTokenRepository $tokens,
        private readonly TileIndicators $indicators,
        private readonly LazyCurrentPeriod $periods,
        private readonly Moment $moment,
    ) {
    }

    /**
     * @return list<array{kind: string, id: string, name: string, type?: string, accent?: string, logo?: string|null, roles: list<array{code: string, label: string, permissions: list<string>}>, period?: array{id: string, kind: string, ordinal: int, starts_on: string, ends_on: string, academic_year: string|null}, indicators?: list<array{key: string, label: string, value: int|float, unit: string}>}>
     */
    public function contexts(User $user, string $locale, bool $withIndicators = false): array
    {
        $assignments = $this->resolver->activeAssignments($user->tenantId, $user->personId);
        if ($assignments === []) {
            return [];
        }
        $catalog = $this->roles->all();
        // Permissions de chaque rôle : le front adapte menus et gardes au rôle actif sans copie locale des rôles des applications.
        $granted = $this->roles->permissionsByRole(array_values(array_unique(array_map(static fn (RoleAssignment $a): string => $a->role, $assignments))));

        $tenantWide = array_values(array_filter($assignments, static fn (RoleAssignment $a): bool => $a->isTenantWide()));
        if ($tenantWide !== []) {
            $infos = $this->directory->forTenant($user->tenantId);
        } else {
            $ids = [];
            foreach ($assignments as $assignment) {
                if ($assignment->establishmentId !== null) {
                    $ids[$assignment->establishmentId] = $assignment->establishmentId;
                    continue;
                }
                if ($assignment->scopeType === ScopeType::Territory) {
                    $territoryId = $assignment->scopeId;
                    if ($territoryId !== null && $territoryId !== '') {
                        foreach ($this->territories->establishmentsIn($user->tenantId, $territoryId, $this->moment->iso()) as $establishmentId) {
                            $ids[$establishmentId] = $establishmentId;
                        }
                    }
                }
            }
            $infos = $ids === [] ? [] : $this->directory->findMany($user->tenantId, array_values($ids));
        }

        $tiles = [];
        foreach ($infos as $info) {
            $codes = [];
            foreach ($assignments as $assignment) {
                if ($this->appliesTo($user->tenantId, $assignment, $info->id)) {
                    $codes[$assignment->role] = $assignment->role;
                }
            }
            $tile = [
                'kind' => 'establishment',
                'id' => $info->id,
                'name' => $info->name,
                'type' => $info->type,
                'accent' => $info->accent,
                'logo' => $info->logoPath,
                'roles' => self::roleList(array_values($codes), $catalog, $locale, $granted),
            ];
            // Indicateurs et période en cours ne sont calculés que pour le lanceur ; la période est omise quand elle est inconnue.
            if ($withIndicators) {
                $period = $this->periods->at($user->tenantId, $info->id, $this->moment->iso());
                if ($period !== null) {
                    $tile['period'] = [
                        'id' => $period->id,
                        'kind' => $period->kind,
                        'ordinal' => $period->ordinal,
                        'starts_on' => $period->startsOn,
                        'ends_on' => $period->endsOn,
                        'academic_year' => $period->academicYearLabel,
                    ];
                }
                $kpis = $this->indicators->forTile($user->tenantId, $info->id, array_values($codes));
                if ($kpis !== []) {
                    $tile['indicators'] = array_slice($kpis, 0, 2);
                }
            }
            $tiles[] = $tile;
        }

        $groupRoles = array_values(array_filter($tenantWide, static fn (RoleAssignment $a): bool => $a->role === Role::GROUP_DIRECTION));
        if ($groupRoles !== []) {
            array_unshift($tiles, [
                'kind' => 'group',
                'id' => self::GROUP_ID,
                'name' => $locale === 'en' ? 'Whole group' : 'Tout le groupe',
                'roles' => self::roleList([Role::GROUP_DIRECTION], $catalog, $locale, $granted),
            ]);
        }

        return $tiles;
    }

    private function appliesTo(string $tenantId, RoleAssignment $assignment, string $establishmentId): bool
    {
        if ($assignment->isTenantWide()) {
            return true;
        }
        if ($assignment->scopeType === ScopeType::Territory) {
            $territoryId = $assignment->scopeId;
            if ($territoryId === null || $territoryId === '') {
                return false;
            }

            return in_array($establishmentId, $this->territories->establishmentsIn($tenantId, $territoryId, $this->moment->iso()), true);
        }

        return $assignment->appliesTo($establishmentId);
    }

    /**
     * Change d'établissement et de rôle sans nouvelle connexion.
     *
     * @return array{context: array<string, mixed>, role: string}
     */
    public function switch(User $user, AccessToken $token, string $contextId, ?string $role, string $locale): array
    {
        $tile = null;
        foreach ($this->contexts($user, $locale) as $candidate) {
            if ($candidate['id'] === $contextId) {
                $tile = $candidate;
                break;
            }
        }
        if ($tile === null) {
            throw HttpException::forbidden('Vous n\'avez pas accès à cet établissement.');
        }
        $codes = array_map(static fn (array $r): string => $r['code'], $tile['roles']);
        if ($role !== null && !in_array($role, $codes, true)) {
            throw HttpException::forbidden('Vous n\'exercez pas ce rôle dans cet établissement.');
        }
        $role ??= $codes[0] ?? null;
        if ($role === null) {
            throw HttpException::forbidden();
        }

        $this->tokens->setContext($token->tenantId, $token->id, $tile['kind'] === 'group' ? null : $tile['id'], $role);

        return ['context' => $tile, 'role' => $role];
    }

    /**
     * @param list<string> $codes
     * @param array<string, Role> $catalog
     * @param array<string, list<string>> $granted permissions par rôle
     * @return list<array{code: string, label: string, permissions: list<string>}>
     */
    private static function roleList(array $codes, array $catalog, string $locale, array $granted): array
    {
        return array_map(
            static fn (string $code): array => [
                'code' => $code,
                'label' => isset($catalog[$code]) ? $catalog[$code]->label($locale) : $code,
                'permissions' => $granted[$code] ?? [],
            ],
            $codes,
        );
    }
}
