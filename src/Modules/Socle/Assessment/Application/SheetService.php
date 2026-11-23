<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Modules\Socle\Assessment\Domain\Num;
use Scholaris\Modules\Socle\Assessment\Domain\RuleEngine;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;
use Scholaris\Modules\Socle\Assessment\Infrastructure\GradeRepository;

/**
 * Grille de saisie (une ligne par élève, une colonne par épreuve) et statistiques de séquence (T118, T121).
 * Lecture seule : rien n'est enregistré ici.
 *
 * @phpstan-import-type Subject from RuleEngine
 */
final class SheetService
{
    public function __construct(
        private readonly ResultService $results,
        private readonly GradeRepository $grades,
        private readonly AssessmentService $assessments,
        private readonly Roster $roster,
        private readonly RuleService $rules,
        private readonly RuleEngine $engine,
    ) {
    }

    /**
     * Grille d'une classe pour une période, éventuellement d'une seule matière.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, counters: array<string, int>, rules_version: int}
     */
    public function grid(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $subjectId): array
    {
        $data = $this->results->inputs($tenantId, $establishmentId, $structureId, $periodId, $subjectId);
        $rules = $this->rules->activeRules($tenantId, $establishmentId, $data['year']);
        $all = $this->allAssessments($tenantId, $establishmentId, $structureId, $periodId, $subjectId);

        $cells = [];
        foreach ($this->grades->ofAssessments($tenantId, array_map(static fn (array $a): string => (string) $a['id'], $all)) as $grade) {
            $cells[(string) $grade['assessment_id']][(string) $grade['person_id']] = $grade;
        }

        $columns = [];
        foreach ($all as $assessment) {
            $columns[] = [
                'id' => $assessment['id'], 'subject_id' => $assessment['subject_id'], 'title' => $assessment['title'], 'kind' => $assessment['kind'],
                'weight' => (float) $assessment['weight'], 'max_score' => (float) $assessment['max_score'], 'status' => $assessment['status'],
                'grading_mode' => $assessment['grading_mode'], 'anonymous' => (int) $assessment['anonymous'] === 1,
            ];
        }

        $counters = ['errors' => 0, 'missing' => 0, 'absent_justified' => 0];
        $rows = [];
        foreach ($data['students'] as $index => $student) {
            $line = [];
            foreach ($all as $assessment) {
                $grade = $cells[(string) $assessment['id']][$student['person_id']] ?? null;
                $line[(string) $assessment['id']] = $this->cell($assessment, $grade, $counters);
            }
            $average = $this->average($rules, $data['inputs'][$student['person_id']] ?? [], $subjectId);
            $rows[] = [
                'number' => $index + 1, 'person_id' => $student['person_id'], 'matricule' => $student['matricule'], 'name' => $student['name'],
                'cells' => $line, 'average' => $average, 'appraisal' => $average === null ? null : $this->engine->mention($rules, $average),
            ];
        }

        return ['columns' => $columns, 'rows' => $rows, 'counters' => $counters, 'rules_version' => $rules->version];
    }

    /**
     * Fiche d'une épreuve : tous les élèves de la classe, identité masquée tant que l'épreuve est anonyme.
     *
     * @return array{assessment: array<string, mixed>, rows: list<array<string, mixed>>, counters: array<string, int>}
     */
    public function sheet(string $tenantId, string $establishmentId, string $assessmentId): array
    {
        $assessment = $this->assessments->assessment($tenantId, $establishmentId, $assessmentId);
        $anonymous = (int) $assessment['anonymous'] === 1;
        $cells = [];
        foreach ($this->grades->ofAssessment($tenantId, $assessmentId) as $grade) {
            $cells[(string) $grade['person_id']] = $grade;
        }

        $counters = ['errors' => 0, 'missing' => 0, 'absent_justified' => 0];
        $rows = [];
        foreach ($this->roster->students($tenantId, $establishmentId, (string) $assessment['structure_id']) as $index => $student) {
            $visible = $assessment;
            $visible['anonymous'] = 0;
            $cell = $this->cell($visible, $cells[$student['person_id']] ?? null, $counters);
            $rows[] = [
                'number' => $index + 1,
                'person_id' => $anonymous ? null : $student['person_id'],
                'matricule' => $anonymous ? null : $student['matricule'],
                'name' => $anonymous ? null : $student['name'],
                'anonymous_code' => $anonymous ? strtoupper(substr(sha1($assessmentId . '|' . $student['person_id']), 0, 6)) : null,
                'cell' => $cell,
            ];
        }
        if ($anonymous) {
            usort($rows, static fn (array $a, array $b): int => $a['anonymous_code'] <=> $b['anonymous_code']);
        }

        return ['assessment' => $assessment, 'rows' => $rows, 'counters' => $counters];
    }

    /**
     * Statistiques de séquence : par épreuve et pour les moyennes des élèves (moyenne, taux de réussite, minimum, maximum).
     *
     * @return array{rules_version: int, scale: float, pass_mark: float, assessments: list<array<string, mixed>>, sequence: array<string, mixed>}
     */
    public function statistics(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $subjectId): array
    {
        $data = $this->results->inputs($tenantId, $establishmentId, $structureId, $periodId, $subjectId);
        $rules = $this->rules->activeRules($tenantId, $establishmentId, $data['year']);

        $perAssessment = [];
        foreach ($data['assessments'] as $assessment) {
            $values = [];
            foreach ($this->grades->ofAssessment($tenantId, (string) $assessment['id']) as $grade) {
                $normalised = $this->normalised($rules, $grade, (float) $assessment['max_score']);
                if ($normalised !== null) {
                    $values[] = $normalised;
                }
            }
            $perAssessment[] = ['assessment_id' => $assessment['id'], 'title' => $assessment['title'], 'subject_id' => $assessment['subject_id']] + $this->summary($rules, $values);
        }

        $averages = [];
        foreach ($data['students'] as $student) {
            $average = $this->average($rules, $data['inputs'][$student['person_id']] ?? [], $subjectId);
            if ($average !== null) {
                $averages[] = $average;
            }
        }

        return [
            'rules_version' => $rules->version, 'scale' => $rules->scale, 'pass_mark' => $rules->passMark,
            'assessments' => $perAssessment, 'sequence' => $this->summary($rules, $averages) + ['students' => count($data['students'])],
        ];
    }

    /**
     * @param list<float> $values valeurs ramenées au barème
     * @return array{count: int, average: float|null, pass_rate: float|null, min: float|null, max: float|null}
     */
    private function summary(RuleSet $rules, array $values): array
    {
        if ($values === []) {
            return ['count' => 0, 'average' => null, 'pass_rate' => null, 'min' => null, 'max' => null];
        }
        $passing = count(array_filter($values, fn (float $v): bool => $this->engine->round($rules, $v) >= $rules->passMark));

        return [
            'count' => count($values),
            'average' => $this->engine->round($rules, array_sum($values) / count($values)),
            'pass_rate' => round(100 * $passing / count($values), 1),
            'min' => $this->engine->round($rules, min($values)),
            'max' => $this->engine->round($rules, max($values)),
        ];
    }

    /** @param array<string, mixed> $grade */
    private function normalised(RuleSet $rules, array $grade, float $max): ?float
    {
        if ($grade['absence'] === 'justified') {
            return null;
        }
        if ($grade['absence'] === 'unjustified') {
            return $rules->unjustifiedAbsence === RuleSet::ZERO ? 0.0 : null;
        }

        return $grade['score'] === null || $max <= 0.0 ? null : (float) $grade['score'] / $max * $rules->scale;
    }

    /**
     * @param list<Subject> $subjects
     */
    private function average(RuleSet $rules, array $subjects, ?string $subjectId): ?float
    {
        if ($subjectId !== null) {
            foreach ($subjects as $subject) {
                if ($subject['subject_id'] === $subjectId) {
                    $average = $this->engine->subjectAverage($rules, $subject);

                    return $average === null ? null : $this->engine->round($rules, $average);
                }
            }

            return null;
        }

        return $this->engine->compute($rules, $subjects)['average'];
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed>|null $grade
     * @param array<string, int> $counters
     * @return array<string, mixed>
     */
    private function cell(array $assessment, ?array $grade, array &$counters): array
    {
        if ((int) $assessment['anonymous'] === 1) {
            return ['state' => 'hidden', 'value' => null, 'absence' => null, 'qualitative' => null, 'version' => $grade === null ? 0 : (int) $grade['version'], 'grade_id' => null, 'message' => null];
        }
        $base = [
            'value' => $grade === null || $grade['score'] === null ? null : (float) $grade['score'],
            'absence' => $grade['absence'] ?? null, 'qualitative' => $grade['qualitative'] ?? null,
            'version' => $grade === null ? 0 : (int) $grade['version'], 'grade_id' => $grade['id'] ?? null, 'message' => null,
        ];
        if ($base['absence'] === 'justified') {
            ++$counters['absent_justified'];

            return ['state' => 'absent_justified'] + $base;
        }
        if ($base['absence'] === 'unjustified') {
            return ['state' => 'absent_unjustified'] + $base;
        }
        if ($base['value'] !== null && $base['value'] > (float) $assessment['max_score']) {
            ++$counters['errors'];

            return ['state' => 'error', 'message' => Num::format($base['value']) . ' dépasse le maximum de ' . Num::format((float) $assessment['max_score']) . '.'] + $base;
        }
        if ($base['value'] === null && $base['qualitative'] === null) {
            ++$counters['missing'];

            return ['state' => 'missing'] + $base;
        }

        return ['state' => 'value'] + $base;
    }

    /** @return list<array<string, mixed>> */
    private function allAssessments(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $subjectId): array
    {
        return $this->results->allAssessments($tenantId, $establishmentId, $structureId, $periodId, $subjectId);
    }
}
