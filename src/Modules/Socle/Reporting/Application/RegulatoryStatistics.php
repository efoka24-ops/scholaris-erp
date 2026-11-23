<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Application;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Statistiques réglementaires simples d'un établissement : effectifs d'inscrits actifs par année, par classe (structure) et
 * par sexe (genre déclaré ; « unknown » pour les personnes sans genre renseigné). Aucun contrat n'expose le niveau d'une classe :
 * cette ventilation est rendue indisponible avec son motif, jamais estimée.
 */
final class RegulatoryStatistics
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly ReportingClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(string $tenantId, string $establishmentId): array
    {
        $counts = $this->counts($tenantId, $establishmentId);

        return [
            'generated_at' => $this->clock->iso(),
            'scope' => ['type' => 'establishment', 'establishment_id' => $establishmentId],
            'total_enrolled' => $counts['total'],
            'by_academic_year' => $this->rows($counts['by_year'], 'academic_year_id'),
            'by_structure' => $this->rows($counts['by_structure'], 'structure_id'),
            'by_level' => ['available' => false, 'reason' => 'Aucun contrat n\'expose le niveau d\'une classe.'],
            'by_sex' => $this->rows($counts['by_sex'], 'sex'),
        ];
    }

    /** Résumé en une ligne pour l'e-mail d'un rapport planifié. */
    public function summary(string $tenantId, string $establishmentId): string
    {
        $counts = $this->counts($tenantId, $establishmentId);
        $years = [];
        foreach ($counts['by_year'] as $year => $enrolled) {
            $years[] = sprintf('%s : %d', $year, $enrolled);
        }

        return sprintf('Inscrits actifs : %d (%s)', $counts['total'], $years === [] ? 'aucune année' : implode(' ; ', $years));
    }

    /** @return array{total: int, by_year: array<string, int>, by_structure: array<string, int>, by_sex: array<string, int>} */
    private function counts(string $tenantId, string $establishmentId): array
    {
        $byYear = [];
        $byStructure = [];
        $bySex = [];
        $total = 0;
        $personIds = array_values(array_unique($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId)));
        $genders = [];
        foreach ($this->people->findMany($tenantId, $personIds) as $person) {
            $genders[$person->id] = $person->gender;
        }
        foreach ($personIds as $personId) {
            foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
                if ($enrollment->tenantId !== $tenantId || $enrollment->establishmentId !== $establishmentId || $enrollment->status !== EnrollmentInfo::ACTIVE) {
                    continue;
                }
                ++$total;
                $byYear[$enrollment->academicYearId] = ($byYear[$enrollment->academicYearId] ?? 0) + 1;
                $structure = $enrollment->structureId ?? '';
                $byStructure[$structure] = ($byStructure[$structure] ?? 0) + 1;
                $gender = $genders[$personId] ?? null;
                $sex = $gender === 'male' || $gender === 'female' ? $gender : 'unknown';
                $bySex[$sex] = ($bySex[$sex] ?? 0) + 1;
            }
        }
        ksort($byYear);
        ksort($byStructure);
        ksort($bySex);

        return ['total' => $total, 'by_year' => $byYear, 'by_structure' => $byStructure, 'by_sex' => $bySex];
    }

    /**
     * @param array<string, int> $counts
     * @return array{available: bool, items: list<array<string, mixed>>}
     */
    private function rows(array $counts, string $key): array
    {
        $items = [];
        foreach ($counts as $id => $count) {
            $items[] = [$key => $id === '' ? null : (string) $id, 'enrolled' => $count];
        }

        return ['available' => true, 'items' => $items];
    }
}
