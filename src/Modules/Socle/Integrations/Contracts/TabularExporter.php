<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/**
 * Export à colonnes configurables par type d'export (S14) : l'application fournit les enregistrements (champs source du type),
 * le socle applique les colonnes en vigueur pour l'établissement et produit un CSV (« ; », UTF-8 avec BOM, cellules neutralisées),
 * un XLSX (XlsxWriter) ou un JSON.
 *
 * Erreurs (HttpException) : 404 type inconnu ; 422 format inconnu ou colonnes paramétrées invalides.
 */
interface TabularExporter
{
    /** @return array<string, string> colonnes en vigueur (en-tête => champ source) */
    public function columns(string $tenantId, ?string $establishmentId, string $exportType): array;

    /** @param list<array<string, scalar|null>> $records */
    public function render(string $tenantId, ?string $establishmentId, string $exportType, array $records, string $format = 'csv'): ExportFile;
}
