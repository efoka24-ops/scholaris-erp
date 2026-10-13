<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/**
 * Port d'export vers cartescolaire.cm (carte scolaire). AUCUNE spécification d'API n'a été fournie : la sortie est un
 * fichier générique (CSV ou JSON) dont les colonnes sont configurées (`config/integrations.php`, clé `cartescolaire`).
 * Un adaptateur réel (API ou format imposé) remplacera l'implémentation sans changer ce port.
 * Voir specs/001-socle-plateforme/integrations-externes.md pour les informations à obtenir de l'organisme.
 */
interface CartescolaireExporter
{
    /** Écrit la liste des élèves actuellement inscrits de l'établissement dans $targetPath. */
    public function export(string $tenantId, string $establishmentId, string $targetPath): ExportReport;
}
