<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Support;

/**
 * Périmètre effectif d'une permission pour une personne dans UNE école (établissement), déjà développé et validé (RG-TER-2) :
 *  - toute l'école (directeur, bureau de l'APEE…) ;
 *  - des classes (enseignant titulaire, R10 : sa classe seulement) ;
 *  - un ou plusieurs territoires qui contiennent l'école (supervision : arrondissement, département, région, national).
 * Un périmètre vide refuse tout. Le périmètre territorial ne donne PAS le droit au nominatif : c'est ScopedByTerritory::assertNamedAccess
 * qui l'accorde (niveau ≤ département, permission nominative, finalité déclarée, journal).
 */
final class Perimeter
{
    /**
     * @param list<string> $classIds classes couvertes (descendants compris)
     * @param list<string> $territoryIds territoires (affectations de supervision) qui contiennent l'école
     * @param list<string> $roles rôles qui donnent ce périmètre
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly string $permission,
        public readonly bool $wholeSchool,
        public readonly array $classIds = [],
        public readonly array $territoryIds = [],
        public readonly array $roles = [],
    ) {
    }

    public static function none(string $tenantId, string $establishmentId, string $permission = ''): self
    {
        return new self($tenantId, $establishmentId, $permission, false);
    }

    public function isEmpty(): bool
    {
        return !$this->wholeSchool && $this->classIds === [] && $this->territoryIds === [];
    }

    /** Vrai si la personne exerce dans l'école elle-même (toute l'école ou des classes), par opposition à la supervision. */
    public function isInSchool(): bool
    {
        return $this->wholeSchool || $this->classIds !== [];
    }

    public function isTerritorial(): bool
    {
        return $this->territoryIds !== [];
    }

    /** Une classe est couverte si l'école entière l'est, si la classe est listée ou si une supervision couvre l'école. */
    public function coversClass(?string $classId): bool
    {
        if ($this->wholeSchool || $this->territoryIds !== []) {
            return true;
        }

        return $classId !== null && in_array($classId, $this->classIds, true);
    }
}
