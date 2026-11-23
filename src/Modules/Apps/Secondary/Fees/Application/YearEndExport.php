<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Apps\Secondary\Fees\Domain\FeesDefaults;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Fichier des conseils de fin d'année pour cartescolaire.cm (DOC-33, API-04, Q1) : une ligne par élève inscrit dans une classe de
 * l'année, avec matricule (CarteScolaire), identité (People), classe (Setup) et, depuis les résultats enregistrés de la période
 * « année » (Assessment), la moyenne annuelle, le rang et la décision du conseil. Colonnes et ordre : paramètre
 * `secondary.carte_scolaire.columns.year_end` (valeurs FeesDefaults::YEAR_END_COLUMNS) ; séparateur `;`, UTF-8 avec BOM, dates
 * AAAA-MM-JJ, moyenne à deux décimales avec une virgule. Aucun appel réseau : le fichier est déposé à la main sur la plateforme.
 */
final class YearEndExport
{
    public function __construct(
        private readonly ClassProfiles $classes,
        private readonly PeriodTree $periods,
        private readonly CouncilDirectory $councils,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly PersonDemographics $demographics,
        private readonly NationalIds $nationalIds,
        private readonly FeesSettings $settings,
    ) {
    }

    /** @return array{csv: string, rows: int, missing_national_ids: int, missing_results: int} */
    public function build(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        $year = null;
        foreach ($this->periods->ofYear($tenantId, $establishmentId, $academicYearId) as $period) {
            if ($period->kind === 'year') {
                $year = $period;
                break;
            }
        }
        if ($year === null) {
            throw HttpException::conflict('Aucune période « année » pour cette année scolaire : la moyenne annuelle ne peut pas être exportée.');
        }
        $columns = $this->columns($tenantId, $establishmentId);
        $lines = [implode(';', $columns)];
        $missingIds = 0;
        $missingResults = 0;
        foreach ($this->classes->forYear($tenantId, $establishmentId, $academicYearId) as $class) {
            $results = $this->councils->storedResults($tenantId, $establishmentId, $class->structureId, $year->id);
            $learners = array_values(array_unique([...$this->enrollments->personIdsInStructure($tenantId, $class->structureId), ...array_keys($results)]));
            if ($learners === []) {
                continue;
            }
            $people = [];
            foreach ($this->people->findMany($tenantId, $learners) as $person) {
                $people[$person->id] = $person;
            }
            $births = $this->demographics->ofPersons($tenantId, $learners);
            usort($learners, static fn (string $a, string $b): int => strcasecmp(isset($people[$a]) ? $people[$a]->lastName . ' ' . $people[$a]->firstName : $a, isset($people[$b]) ? $people[$b]->lastName . ' ' . $people[$b]->firstName : $b));
            foreach ($learners as $personId) {
                $person = $people[$personId] ?? null;
                $result = $results[$personId] ?? null;
                $nationalId = $this->nationalIds->of($tenantId, $establishmentId, $personId);
                $nationalId === null && ++$missingIds;
                $result === null && ++$missingResults;
                $values = [
                    'matricule' => $nationalId ?? '', 'nom' => $person->lastName ?? '', 'prenoms' => $person->firstName ?? '',
                    'date_naissance' => $births[$personId]['birth_date'] ?? '', 'classe' => $class->label,
                    'moyenne_annuelle' => $result === null || $result['average'] === null ? '' : number_format($result['average'], 2, ',', ''),
                    'rang' => $result === null || $result['rank'] === null ? '' : (string) $result['rank'], 'decision' => (string) ($result['decision'] ?? ''),
                ];
                $lines[] = implode(';', array_map(static fn (string $column): string => self::cell($values[$column] ?? ''), $columns));
            }
        }

        return ['csv' => "\u{FEFF}" . implode("\r\n", $lines) . "\r\n", 'rows' => count($lines) - 1, 'missing_national_ids' => $missingIds, 'missing_results' => $missingResults];
    }

    /** @return list<string> */
    private function columns(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'secondary.carte_scolaire.columns.year_end', null);
        if (!is_array($raw) || $raw === []) {
            return FeesDefaults::YEAR_END_COLUMNS;
        }
        $columns = array_values(array_filter($raw, static fn (mixed $c): bool => is_string($c) && in_array($c, FeesDefaults::YEAR_END_COLUMNS, true)));

        return $columns === [] ? FeesDefaults::YEAR_END_COLUMNS : $columns;
    }

    private static function cell(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/^[=+\-@]/', $value) === 1) {
            $value = "'" . $value;
        }

        return str_contains($value, ';') || str_contains($value, '"') ? '"' . str_replace('"', '""', $value) . '"' : $value;
    }
}
