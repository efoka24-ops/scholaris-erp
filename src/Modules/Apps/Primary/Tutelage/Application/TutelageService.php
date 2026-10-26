<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Tutelage\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Councils\Application\CouncilService;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\ClassFigures;
use Scholaris\Modules\Apps\Primary\Exams\Application\ExamService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Apps\Primary\Tutelage\Domain\PrimaryExportTypes;
use Scholaris\Modules\Socle\Integrations\Contracts\ExportFile;
use Scholaris\Modules\Socle\Integrations\Contracts\TabularExporter;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;

/**
 * Exports à la tutelle (EX-PRI-45) : effectifs par classe et par sexe (redoublants, plafond paramétré `primary.class_size_cap`),
 * résultats des conseils validés, recensement par niveau, sexe et âge (date de référence paramétrée), examen de fin de cycle.
 * CSV, XLSX ou JSON par le socle (TabularExporter : colonnes paramétrables, cellules neutralisées). Agrégats seulement.
 */
final class TutelageService
{
    public const PERMISSION = ['primary.tutelage.export'];
    public const FORMATS = ['csv', 'xlsx', 'json'];

    public function __construct(
        private readonly Access $access,
        private readonly Store $store,
        private readonly ClassFigures $classes,
        private readonly OrgUnitTree $tree,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDemographics $demographics,
        private readonly PrimarySettings $settings,
        private readonly ExamService $exams,
        private readonly TabularExporter $exporter,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return list<array{key: string, label: string, columns: array<string, string>}> */
    public function types(Principal $principal, string $establishmentId): array
    {
        $this->authorise($principal, $establishmentId);
        $out = [];
        foreach (PrimaryExportTypes::types() as $type) {
            $out[] = ['key' => $type->key(), 'label' => $type->label(), 'columns' => $this->exporter->columns($principal->tenantId, $establishmentId, $type->key())];
        }

        return $out;
    }

    public function export(Principal $principal, string $establishmentId, string $type, ?string $yearId, ?string $referenceDate, string $format): ExportFile
    {
        $this->authorise($principal, $establishmentId);
        if (!in_array($format, self::FORMATS, true)) {
            throw HttpException::validation(['format' => ['Valeurs possibles : ' . implode(', ', self::FORMATS) . '.']]);
        }

        return $this->exporter->render($principal->tenantId, $establishmentId, $type, $this->records($principal->tenantId, $establishmentId, $type, $yearId, $referenceDate), $format);
    }

    /** @return list<array<string, scalar|null>> */
    public function records(string $tenantId, string $establishmentId, string $type, ?string $yearId, ?string $referenceDate): array
    {
        return match ($type) {
            PrimaryExportTypes::ENROLMENT => $this->enrolment($tenantId, $establishmentId, $yearId),
            PrimaryExportTypes::RESULTS => $this->results($tenantId, $establishmentId, $yearId),
            PrimaryExportTypes::CENSUS => $this->census($tenantId, $establishmentId, $referenceDate),
            PrimaryExportTypes::EXAM => $this->examRows($tenantId, $establishmentId, $yearId),
            default => throw HttpException::notFound('Type d\'export inconnu.'),
        };
    }

    /** @return list<array<string, scalar|null>> */
    private function enrolment(string $t, string $e, ?string $yearId): array
    {
        $cap = $this->settings->int($t, $e, 'primary.class_size_cap');
        $rows = [];
        foreach ($this->classes->all($t, $e) as $class) {
            $ids = $this->inYear($t, $e, $class->learnerIds, $class->id, $yearId);
            $demo = $this->demographics->ofPersons($t, $ids);
            $boys = count(array_filter($ids, static fn (string $id): bool => ($demo[$id]['gender'] ?? null) === 'male'));
            $girls = count(array_filter($ids, static fn (string $id): bool => ($demo[$id]['gender'] ?? null) === 'female'));
            $repeaters = count(array_filter($ids, fn (string $id): bool => $this->isRepeater($t, $e, $id, $class->levelId, $yearId)));
            $rows[] = [
                'level' => $this->name($t, $e, $class->levelId), 'class' => $class->name, 'boys' => $boys, 'girls' => $girls,
                'gender_unknown' => count($ids) - $boys - $girls, 'total' => count($ids), 'repeaters' => $repeaters, 'capacity' => $class->capacity,
                'class_size_cap' => $cap, 'over_cap' => $cap > 0 && count($ids) > $cap ? 'oui' : 'non',
            ];
        }

        return $rows;
    }

    /** @return list<array<string, scalar|null>> */
    private function results(string $t, string $e, ?string $yearId): array
    {
        $where = ['status' => 'validated'];
        if ($yearId !== null) {
            $where['academic_year_id'] = $yearId;
        }
        $rows = [];
        foreach ($this->store->select(CouncilService::COUNCILS, $t, $e, $where, 'term_ordinal, class_structure_id', 2000) as $council) {
            $summary = json_decode((string) ($council['summary'] ?? '{}'), true);
            $summary = is_array($summary) ? $summary : [];
            $decisions = is_array($summary['decisions'] ?? null) ? $summary['decisions'] : [];
            $admitted = (int) ($decisions['admitted'] ?? 0);
            $adjourned = (int) ($decisions['adjourned'] ?? 0);
            $rows[] = [
                'level' => $council['level_structure_id'] === null ? '' : $this->name($t, $e, (string) $council['level_structure_id']),
                'class' => $this->name($t, $e, (string) $council['class_structure_id']), 'period' => (string) $council['period_label'],
                'learners' => (int) ($summary['learners'] ?? 0), 'with_average' => (int) ($summary['with_average'] ?? 0),
                'class_average' => is_int($summary['class_average'] ?? null) || is_float($summary['class_average'] ?? null) ? (float) $summary['class_average'] : null,
                'admitted' => $admitted, 'adjourned' => $adjourned, 'cycle_end' => (int) ($decisions['cycle_end'] ?? 0),
                'pass_rate' => $admitted + $adjourned === 0 ? null : round($admitted * 100 / ($admitted + $adjourned), 1),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, scalar|null>> */
    private function census(string $t, string $e, ?string $referenceDate): array
    {
        if ($referenceDate === null) {
            $md = $this->settings->string($t, $e, 'primary.tutelage.census_reference') ?? '01-01';
            $referenceDate = substr($this->clock->today(), 0, 4) . '-' . (preg_match('/^\d{2}-\d{2}$/', $md) === 1 ? $md : '01-01');
        }
        $reference = new \DateTimeImmutable($referenceDate);
        $buckets = [];
        foreach ($this->classes->all($t, $e) as $class) {
            foreach ($this->demographics->ofPersons($t, $class->learnerIds) as $info) {
                $age = $info['birth_date'] === null ? null : (new \DateTimeImmutable($info['birth_date']))->diff($reference)->y;
                $key = $class->levelId . '|' . ($info['gender'] ?? '') . '|' . ($age ?? '');
                $buckets[$key] = ($buckets[$key] ?? 0) + 1;
            }
        }
        ksort($buckets);
        $rows = [];
        foreach ($buckets as $key => $count) {
            [$level, $gender, $age] = explode('|', $key);
            $rows[] = ['level' => $this->name($t, $e, $level), 'gender' => $gender === '' ? null : $gender, 'age' => $age === '' ? null : (int) $age, 'count' => $count];
        }

        return $rows;
    }

    /** @return list<array<string, scalar|null>> */
    private function examRows(string $t, string $e, ?string $yearId): array
    {
        $rows = [];
        foreach ($this->store->select(ExamService::SESSIONS, $t, $e, $yearId === null ? [] : ['academic_year_id' => $yearId], 'exam_code', 200) as $session) {
            $stats = $this->exams->stats($t, $e, (string) $session['id']);
            $rows[] = [
                'exam' => (string) $session['label'], 'level' => $this->name($t, $e, (string) $session['level_structure_id']),
                'centre' => $session['centre_label'] === null ? null : (string) $session['centre_label'], 'sub_centre' => $session['sub_centre_label'] === null ? null : (string) $session['sub_centre_label'],
                'registered' => $stats['registered'], 'withdrawn' => $stats['withdrawn'], 'passed' => $stats['passed'], 'failed' => $stats['failed'],
                'absent' => $stats['absent'], 'pass_rate' => $stats['pass_rate'],
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function inYear(string $t, string $e, array $ids, string $classId, ?string $yearId): array
    {
        if ($yearId === null) {
            return $ids;
        }

        return array_values(array_filter($ids, function (string $id) use ($t, $e, $classId, $yearId): bool {
            foreach ($this->enrollments->forPerson($t, $id) as $enrollment) {
                if ($enrollment->establishmentId === $e && $enrollment->structureId === $classId && $enrollment->academicYearId === $yearId) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function isRepeater(string $t, string $e, string $personId, string $levelId, ?string $yearId): bool
    {
        $years = [];
        foreach ($this->enrollments->forPerson($t, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $e && $enrollment->structureId !== null && $this->tree->levelOf($t, $e, $enrollment->structureId) === $levelId) {
                $years[$enrollment->academicYearId] = true;
            }
        }
        unset($years[(string) $yearId]);

        return $yearId === null ? count($years) > 1 : $years !== [];
    }

    private function name(string $t, string $e, string $structureId): string
    {
        return $this->tree->structure($t, $e, $structureId)->name ?? '';
    }

    private function authorise(Principal $principal, string $establishmentId): void
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::PERMISSION);
        $this->access->assertWhole($principal, $perimeter, 'primary_tutelage');
    }
}
