<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Application;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Integrations\Contracts\CartescolaireExporter;
use Scholaris\Modules\Socle\Integrations\Contracts\ExportReport;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Export fichier générique de la liste des élèves inscrits (statut actif). Les colonnes viennent de `integrations.cartescolaire.columns`
 * (en-tête => champ source). Aucune donnée sensible (santé, pièce d'identité) n'est disponible comme champ source.
 */
final class CartescolaireFileExporter implements CartescolaireExporter
{
    public const FIELDS = [
        'person_id', 'internal_number', 'last_name', 'first_name', 'birth_date', 'phone', 'email',
        'enrollment_status', 'structure_id', 'academic_year_id', 'establishment_id', 'establishment_name', 'establishment_type',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ExportFileWriter $writer,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly BirthInfo $births,
        private readonly EstablishmentDirectory $establishments,
    ) {
    }

    public function export(string $tenantId, string $establishmentId, string $targetPath): ExportReport
    {
        $establishment = $this->establishments->find($tenantId, $establishmentId);
        $records = [];
        if ($establishment !== null) {
            $ids = $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId);
            $byId = [];
            foreach (array_chunk($ids, 200) as $chunk) {
                foreach ($this->people->findMany($tenantId, $chunk) as $person) {
                    $byId[$person->id] = $person;
                }
            }
            foreach ($ids as $personId) {
                $person = $byId[$personId] ?? null;
                if ($person === null) {
                    continue;
                }
                $enrollment = $this->activeEnrollment($tenantId, $personId, $establishmentId);
                $records[] = [
                    'person_id' => $person->id,
                    'internal_number' => $enrollment?->internalNumber,
                    'last_name' => $person->lastName,
                    'first_name' => $person->firstName,
                    'birth_date' => $this->births->birthDate($tenantId, $personId),
                    'phone' => $person->phone,
                    'email' => $person->email,
                    'enrollment_status' => $enrollment?->status,
                    'structure_id' => $enrollment?->structureId,
                    'academic_year_id' => $enrollment?->academicYearId,
                    'establishment_id' => $establishment->id,
                    'establishment_name' => $establishment->name,
                    'establishment_type' => $establishment->type,
                ];
            }
            usort($records, static fn (array $a, array $b): int => [$a['last_name'], $a['first_name']] <=> [$b['last_name'], $b['first_name']]);
        }

        return $this->writer->write(
            $targetPath,
            (string) $this->config->get('integrations.cartescolaire.format', 'csv'),
            (string) $this->config->get('integrations.cartescolaire.delimiter', ';'),
            array_map('strval', (array) $this->config->get('integrations.cartescolaire.columns', [])),
            $records,
            self::FIELDS,
        );
    }

    private function activeEnrollment(string $tenantId, string $personId, string $establishmentId): ?EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && $enrollment->status === EnrollmentInfo::ACTIVE) {
                return $enrollment;
            }
        }

        return null;
    }
}
