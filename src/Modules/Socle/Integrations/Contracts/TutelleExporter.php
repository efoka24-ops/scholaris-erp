<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/**
 * Port d'export vers l'autorité de tutelle (ministère, délégation régionale ou départementale). Aucun format officiel n'a
 * été fourni : sortie fichier générique (CSV ou JSON), colonnes configurées (`config/integrations.php`, clé `tutelle`).
 * Voir specs/001-socle-plateforme/integrations-externes.md.
 */
interface TutelleExporter
{
    /** Écrit les statistiques de l'établissement (effectifs, personnel enseignant) dans $targetPath. */
    public function export(string $tenantId, string $establishmentId, string $targetPath): ExportReport;
}
