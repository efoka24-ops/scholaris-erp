<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/**
 * Type d'export vers un organisme (S14) : liste MINESUP BTS ou HND, inscrits pour une université de tutelle, statistiques
 * d'effectifs... Il déclare les champs source permis et ses colonnes par défaut ; l'établissement peut les remplacer par le
 * paramètre `columnsSetting()` (objet « en-tête => champ » ou liste de champs), sans code.
 *
 * Enregistrement : clé de liste du conteneur `integrations.export_types.<application>` => [instance de ConfiguredExportType
 * ou classe, ...].
 */
interface ExportType
{
    /** Clé du type (ex. minesup.bts, tutelle.uy2). */
    public function key(): string;

    public function label(): string;

    /** @return list<string> champs source que l'application fournit pour ce type */
    public function fields(): array;

    /** @return array<string, string> colonnes par défaut : en-tête => champ source */
    public function defaultColumns(): array;

    /** Clé de paramètre qui remplace les colonnes (ex. higher.minesup.export.bts.columns), ou null. */
    public function columnsSetting(): ?string;
}
