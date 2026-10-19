<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Dashboards\Application;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicLabels;
use Scholaris\Modules\Apps\Higher\Dashboards\Domain\SmallCells;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentStatistics;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;

/**
 * Statistiques annuelles d'effectifs MINESUP (DOC-32) : inscrits par sexe, tranche d'âge, filière et niveau. Les caractéristiques des
 * personnes sont lues en un lot (PersonDemographics) ; seuls des agrégats sortent (jamais d'identité) et les petits effectifs sont
 * masqués. La région d'origine n'est portée par aucune donnée du socle : elle est déclarée indisponible, jamais estimée.
 * L'âge est calculé au 31 décembre de l'année de la date du jour.
 */
final class MinesupStatistics
{
    public const AGE_BANDS = ['17-', '18-19', '20-21', '22-23', '24-25', '26-29', '30+', 'unknown'];

    public function __construct(
        private readonly EnrolmentStatistics $enrolments,
        private readonly PersonDemographics $people,
        private readonly AcademicLabels $labels,
    ) {
    }

    /** @return array{cards: list<array<string, mixed>>, tables: list<array<string, mixed>>, notes: list<string>} */
    public function build(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter, string $today): array
    {
        $enrolled = $this->enrolments->enrolledPersons($tenantId, $establishmentId, $perimeter, $filter);
        $demographics = $this->people->ofPersons($tenantId, array_column($enrolled, 'person_id'));
        $reference = substr($today, 0, 4) . '-12-31';

        $bySex = [];
        $byAge = [];
        $byProgram = [];
        $byLevel = [];
        foreach ($enrolled as $row) {
            $info = $demographics[$row['person_id']] ?? ['gender' => null, 'birth_date' => null];
            $sex = $info['gender'] === 'male' || $info['gender'] === 'female' ? $info['gender'] : 'unknown';
            $band = self::band($info['birth_date'], $reference);
            $bySex[$sex] = ($bySex[$sex] ?? 0) + 1;
            $byAge[$band][$sex] = ($byAge[$band][$sex] ?? 0) + 1;
            $byProgram[$row['program_id']][$sex] = ($byProgram[$row['program_id']][$sex] ?? 0) + 1;
            $byLevel[$row['program_id'] . '|' . $row['level_id']][$sex] = ($byLevel[$row['program_id'] . '|' . $row['level_id']][$sex] ?? 0) + 1;
        }
        $total = count($enrolled);
        $programNames = $this->labels->names($tenantId, $establishmentId, AcademicLabels::PROGRAM, array_keys($byProgram));
        $levelIds = [];
        foreach (array_keys($byLevel) as $key) {
            $levelIds[] = explode('|', $key)[1];
        }
        $levelNames = $this->labels->names($tenantId, $establishmentId, AcademicLabels::LEVEL, $levelIds);

        $sexRows = [];
        foreach (['male', 'female', 'unknown'] as $sex) {
            $sexRows[] = ['sex' => $sex, 'enrolled' => $bySex[$sex] ?? 0];
        }
        $ageRows = [];
        foreach (self::AGE_BANDS as $band) {
            $ageRows[] = self::split(['age_band' => $band], $byAge[$band] ?? []);
        }
        $programRows = [];
        foreach ($byProgram as $programId => $split) {
            $programRows[] = self::split(['program' => $programNames[$programId] ?? $programId], $split);
        }
        $levelRows = [];
        foreach ($byLevel as $key => $split) {
            [$programId, $levelId] = explode('|', $key);
            $levelRows[] = self::split(['program' => $programNames[$programId] ?? $programId, 'level' => $levelNames[$levelId] ?? $levelId], $split);
        }
        usort($programRows, static fn (array $a, array $b): int => strcmp((string) $a['program'], (string) $b['program']));
        usort($levelRows, static fn (array $a, array $b): int => strcmp($a['program'] . $a['level'], $b['program'] . $b['level']));
        $split = ['male', 'female', 'unknown', 'enrolled'];

        return [
            'cards' => [
                ['key' => 'enrolled', 'value' => SmallCells::card($total), 'unit' => 'count'],
                ['key' => 'female_share', 'value' => $total >= SmallCells::MINIMUM ? DashboardService::rate($bySex['female'] ?? 0, $total) : null, 'unit' => 'percent'],
            ],
            'tables' => [
                ['key' => 'by_sex', 'columns' => ['sex', 'enrolled'], 'rows' => SmallCells::apply($sexRows, ['enrolled'], ['enrolled'])],
                ['key' => 'by_age', 'columns' => ['age_band', 'male', 'female', 'unknown', 'enrolled'], 'rows' => SmallCells::apply($ageRows, ['male', 'female', 'unknown'], $split)],
                ['key' => 'by_program', 'columns' => ['program', 'male', 'female', 'unknown', 'enrolled'], 'rows' => SmallCells::apply($programRows, ['male', 'female', 'unknown'], $split)],
                ['key' => 'by_level', 'columns' => ['program', 'level', 'male', 'female', 'unknown', 'enrolled'], 'rows' => SmallCells::apply($levelRows, ['male', 'female', 'unknown'], $split)],
            ],
            'notes' => ['Région d\'origine indisponible : aucune donnée du socle ne la porte.', 'Âge au 31 décembre de l\'année en cours.'],
        ];
    }

    /**
     * @param array<string, string> $head
     * @param array<string, int> $counts
     * @return array<string, mixed>
     */
    private static function split(array $head, array $counts): array
    {
        $male = $counts['male'] ?? 0;
        $female = $counts['female'] ?? 0;
        $unknown = $counts['unknown'] ?? 0;

        return $head + ['male' => $male, 'female' => $female, 'unknown' => $unknown, 'enrolled' => $male + $female + $unknown];
    }

    private static function band(?string $birthDate, string $reference): string
    {
        if ($birthDate === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthDate, $m) !== 1) {
            return 'unknown';
        }
        $age = (int) substr($reference, 0, 4) - (int) $m[1];
        if ($age < 0 || $age > 100) {
            return 'unknown';
        }

        return match (true) {
            $age <= 17 => '17-',
            $age <= 19 => '18-19',
            $age <= 21 => '20-21',
            $age <= 23 => '22-23',
            $age <= 25 => '24-25',
            $age <= 29 => '26-29',
            default => '30+',
        };
    }
}
