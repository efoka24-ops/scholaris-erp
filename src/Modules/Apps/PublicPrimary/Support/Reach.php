<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Support;

/**
 * Périmètre effectif d'une permission dans UNE école (RG-TER-2) : toute l'école (directeur), des classes (enseignant titulaire, R10)
 * ou des territoires qui contiennent l'école (supervision). Un périmètre vide refuse tout.
 */
final class Reach
{
    /**
     * @param list<string> $classIds
     * @param list<string> $territoryIds
     * @param list<string> $roles
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

    public function isEmpty(): bool
    {
        return !$this->wholeSchool && $this->classIds === [] && $this->territoryIds === [];
    }

    public function isInSchool(): bool
    {
        return $this->wholeSchool || $this->classIds !== [];
    }

    public function isTerritorial(): bool
    {
        return $this->territoryIds !== [];
    }

    /** Classe couverte dans l'école (l'école entière ou la classe listée) ; la supervision ne couvre pas l'écriture d'une classe. */
    public function coversClass(string $classId): bool
    {
        return $this->wholeSchool || in_array($classId, $this->classIds, true);
    }
}
