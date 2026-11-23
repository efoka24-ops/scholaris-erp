<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Support;

/**
 * Périmètre effectif d'une permission pour une personne dans un établissement (RG-ORG-2), déjà développé :
 * unités internes (écoles et départements) et structures (filières, niveaux, groupes) couvertes, écoles sélectionnables (RG-ORG-3).
 * Un périmètre vide refuse tout ; `wholeEstablishment` couvre tout l'établissement.
 */
final class Perimeter
{
    /**
     * @param list<string> $orgUnitIds écoles et départements couverts (sous-unités comprises)
     * @param list<string> $structureIds filières, niveaux et groupes couverts (descendants compris)
     * @param list<string> $schoolIds écoles où la personne exerce (sélecteur « École ») ; vide avec wholeEstablishment = toutes
     * @param list<string> $roles rôles qui donnent ce périmètre
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly bool $wholeEstablishment,
        public readonly array $orgUnitIds = [],
        public readonly array $structureIds = [],
        public readonly array $schoolIds = [],
        public readonly array $roles = [],
    ) {
    }

    public static function none(string $tenantId, string $establishmentId): self
    {
        return new self($tenantId, $establishmentId, false);
    }

    public function isEmpty(): bool
    {
        return !$this->wholeEstablishment && $this->orgUnitIds === [] && $this->structureIds === [];
    }

    public function coversOrgUnit(?string $id): bool
    {
        return $id !== null && ($this->wholeEstablishment || in_array($id, $this->orgUnitIds, true));
    }

    public function coversStructure(?string $id): bool
    {
        return $id !== null && ($this->wholeEstablishment || in_array($id, $this->structureIds, true));
    }

    /** Sélecteur « École » : affiché seulement à qui exerce dans plusieurs écoles (ou sur tout l'établissement). */
    public function showsSchoolSelector(): bool
    {
        return $this->wholeEstablishment || count($this->schoolIds) > 1;
    }
}
