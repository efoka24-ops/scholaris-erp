<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Support;

/**
 * Portée d'une section de maternelle : tout l'établissement, ou la liste des identifiants de sections (classes, groupes) que
 * le rôle de l'utilisateur couvre. Se construit avec les identifiants permis, ou par `wholeEstablishment()`.
 */
final class SectionScope
{
    /** @var list<string> */
    private readonly array $sectionIds;
    private bool $whole = false;

    public function __construct(string ...$sectionIds)
    {
        $this->sectionIds = array_values(array_unique($sectionIds));
    }

    public static function wholeEstablishment(): self
    {
        $scope = new self();
        $scope->whole = true;

        return $scope;
    }

    public function isWholeEstablishment(): bool
    {
        return $this->whole;
    }

    /** @return list<string> */
    public function sectionIds(): array
    {
        return $this->sectionIds;
    }

    public function contains(string $sectionId): bool
    {
        return $this->whole || in_array($sectionId, $this->sectionIds, true);
    }
}
