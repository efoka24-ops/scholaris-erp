<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/**
 * Section d'application du questionnaire statistique annuel (STA) : la Maternelle publique y ajoute sa section (sections,
 * effectifs, encadrement), le Primaire la sienne. Enregistré dans la liste de conteneur `public_schools.census_sections.<application>` ;
 * PublicSchools\Census collecte les valeurs, applique les contrôles de cohérence et fait circuler le questionnaire (PP5).
 * Le fournisseur ne lit que ses propres tables et les Contracts du socle.
 */
interface CensusSectionProvider
{
    /** Clé stable de la section dans le questionnaire (ex. `nursery_sections`). */
    public function sectionKey(): string;

    /** Titre de la section dans la langue demandée (`fr` ou `en`). */
    public function title(string $locale): string;

    /**
     * Valeurs pré-remplies de la section pour l'école et l'année scolaire (ex. `2026-2027`) ; clés = champs de la section.
     *
     * @return array<string, int|float|string|null>
     */
    public function values(string $tenantId, string $establishmentId, string $yearLabel): array;

    /**
     * Contrôles de cohérence sur les valeurs saisies (RG-STA) : codes d'anomalie bloquants ou à justifier ; liste vide si tout est cohérent.
     *
     * @param array<string, int|float|string|null> $values
     * @return list<string>
     */
    public function controls(string $tenantId, string $establishmentId, string $yearLabel, array $values): array;
}
