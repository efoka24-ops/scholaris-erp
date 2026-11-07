<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Application;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Integrations\Contracts\ExportReport;
use Scholaris\Modules\Socle\Integrations\Contracts\TutelleExporter;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/**
 * Export fichier générique des statistiques d'un établissement pour la tutelle : une ligne par établissement
 * (effectif des inscrits actifs, enseignants). Colonnes dans `integrations.tutelle.columns` (en-tête => champ source).
 */
final class TutelleFileExporter implements TutelleExporter
{
    public const FIELDS = ['establishment_id', 'establishment_name', 'establishment_type', 'enrolled_learners', 'teachers'];

    public function __construct(
        private readonly Config $config,
        private readonly ExportFileWriter $writer,
        private readonly EnrollmentDirectory $enrollments,
        private readonly StaffDirectory $staff,
        private readonly EstablishmentDirectory $establishments,
    ) {
    }

    public function export(string $tenantId, string $establishmentId, string $targetPath): ExportReport
    {
        $establishment = $this->establishments->find($tenantId, $establishmentId);
        $records = $establishment === null ? [] : [[
            'establishment_id' => $establishment->id,
            'establishment_name' => $establishment->name,
            'establishment_type' => $establishment->type,
            'enrolled_learners' => count($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId)),
            'teachers' => count($this->staff->teacherIdsIn($tenantId, $establishmentId)),
        ]];

        return $this->writer->write(
            $targetPath,
            (string) $this->config->get('integrations.tutelle.format', 'csv'),
            (string) $this->config->get('integrations.tutelle.delimiter', ';'),
            array_map('strval', (array) $this->config->get('integrations.tutelle.columns', [])),
            $records,
            self::FIELDS,
        );
    }
}
