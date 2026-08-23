<?php

declare(strict_types=1);

namespace Scholaris\Service;

use Scholaris\Database\Connection;
use Scholaris\Database\Table;
use Scholaris\Tenant\TenantContext;

/**
 * Moteur de calcul des moyennes.
 *
 * Chaine : notes -> moyenne par matiere -> moyenne generale ponderee par les
 * coefficients -> rang dans la classe.
 *
 * Service sans etat : il lit les notes, ecrit les resultats, et peut etre
 * rejoue autant de fois que necessaire sans effet cumulatif.
 */
final class GradeCalculator
{
    private Connection $db;

    private TenantContext $tenant;

    public function __construct(Connection $db, TenantContext $tenant)
    {
        $this->db = $db;
        $this->tenant = $tenant;
    }

    /**
     * Calcule les moyennes d'une classe pour une periode.
     *
     * @return array{students: int, subjects: int}
     */
    public function calculate(string $classroomId, string $periodId): array
    {
        $tenantId = $this->tenant->requireId();

        $students = $this->db->select(
            'SELECT s.id, s.first_name, s.last_name
             FROM enrollments e INNER JOIN students s ON s.id = e.student_id
             INNER JOIN periods p ON p.academic_year_id = e.academic_year_id
             WHERE e.tenant_id = :tenant AND e.classroom_id = :classroom
               AND p.id = :period AND e.status = :status AND e.deleted_at IS NULL
             ORDER BY s.last_name, s.first_name',
            [
                'tenant' => $tenantId,
                'classroom' => $classroomId,
                'period' => $periodId,
                'status' => 'ACTIVE',
            ]
        );

        if ($students === []) {
            return ['students' => 0, 'subjects' => 0];
        }

        $subjects = $this->db->select(
            'SELECT DISTINCT s.id, s.coefficient, s.is_eliminatory, s.eliminatory_threshold
             FROM subject_assignments a INNER JOIN subjects s ON s.id = a.subject_id
             WHERE a.tenant_id = :tenant AND a.classroom_id = :classroom AND a.deleted_at IS NULL',
            ['tenant' => $tenantId, 'classroom' => $classroomId]
        );

        $averages = [];

        foreach ($students as $student) {
            $studentId = (string) $student['id'];
            $weightedTotal = 0.0;
            $coefficientTotal = 0.0;

            foreach ($subjects as $subject) {
                $average = $this->subjectAverage($studentId, (string) $subject['id'], $periodId);

                if ($average === null) {
                    continue;
                }

                $coefficient = (float) $subject['coefficient'];
                $weightedTotal += $average * $coefficient;
                $coefficientTotal += $coefficient;

                $this->storeSubjectAverage(
                    $studentId,
                    (string) $subject['id'],
                    $periodId,
                    $classroomId,
                    $average,
                    $coefficient
                );
            }

            // Sans aucune note, l'eleve n'a pas de moyenne : mieux vaut
            // l'absence de resultat qu'un zero qui fausserait le classement.
            $averages[$studentId] = $coefficientTotal > 0
                ? round($weightedTotal / $coefficientTotal, 2)
                : null;
        }

        $this->storePeriodResults($averages, $classroomId, $periodId);

        return ['students' => count($students), 'subjects' => count($subjects)];
    }

    /**
     * Moyenne d'un eleve dans une matiere sur une periode, ponderee par le
     * poids de chaque evaluation et ramenee sur 20.
     *
     * Une absence non justifiee compte zero ; une absence justifiee est ignoree,
     * pour ne pas penaliser un eleve malade.
     */
    private function subjectAverage(string $studentId, string $subjectId, string $periodId): ?float
    {
        $grades = $this->db->select(
            'SELECT value, max_value, weight, is_absent, is_justified
             FROM grades
             WHERE tenant_id = :tenant AND student_id = :student
               AND subject_id = :subject AND period_id = :period AND deleted_at IS NULL',
            [
                'tenant' => $this->tenant->requireId(),
                'student' => $studentId,
                'subject' => $subjectId,
                'period' => $periodId,
            ]
        );

        $weightedTotal = 0.0;
        $weightTotal = 0.0;

        foreach ($grades as $grade) {
            $isAbsent = (int) $grade['is_absent'] === 1;
            $isJustified = (int) $grade['is_justified'] === 1;

            if ($isAbsent && $isJustified) {
                continue;
            }

            $weight = (float) $grade['weight'];
            $maxValue = (float) $grade['max_value'];

            if ($weight <= 0 || $maxValue <= 0) {
                continue;
            }

            $raw = $isAbsent ? 0.0 : (float) ($grade['value'] ?? 0);
            $normalized = $raw * 20 / $maxValue;

            $weightedTotal += $normalized * $weight;
            $weightTotal += $weight;
        }

        return $weightTotal > 0 ? round($weightedTotal / $weightTotal, 2) : null;
    }

    private function storeSubjectAverage(
        string $studentId,
        string $subjectId,
        string $periodId,
        string $classroomId,
        float $average,
        float $coefficient
    ): void {
        $existing = $this->db->scalar(
            'SELECT id FROM grade_calculations
             WHERE tenant_id = :tenant AND student_id = :student
               AND subject_id = :subject AND period_id = :period',
            [
                'tenant' => $this->tenant->requireId(),
                'student' => $studentId,
                'subject' => $subjectId,
                'period' => $periodId,
            ]
        );

        $now = date('Y-m-d H:i:s');
        $weighted = round($average * $coefficient, 2);

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE grade_calculations
                 SET calculated_average = :average, coefficient = :coefficient,
                     weighted_total = :weighted, classroom_id = :classroom, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'average' => $average,
                    'coefficient' => $coefficient,
                    'weighted' => $weighted,
                    'classroom' => $classroomId,
                    'updated_at' => $now,
                    'id' => $existing,
                ]
            );

            return;
        }

        $this->db->execute(
            'INSERT INTO grade_calculations
                (id, tenant_id, student_id, period_id, subject_id, classroom_id,
                 calculated_average, coefficient, weighted_total, created_at, updated_at)
             VALUES (:id, :tenant, :student, :period, :subject, :classroom,
                 :average, :coefficient, :weighted, :created_at, :updated_at)',
            [
                'id' => Table::uuid(),
                'tenant' => $this->tenant->requireId(),
                'student' => $studentId,
                'period' => $periodId,
                'subject' => $subjectId,
                'classroom' => $classroomId,
                'average' => $average,
                'coefficient' => $coefficient,
                'weighted' => $weighted,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /**
     * Enregistre moyenne generale, rang et mention.
     *
     * @param  array<string, float|null>  $averages
     */
    private function storePeriodResults(array $averages, string $classroomId, string $periodId): void
    {
        // Classement : les ex aequo partagent le meme rang, et le rang suivant
        // saute d'autant (1, 2, 2, 4) comme sur un bulletin papier.
        $ranked = array_filter($averages, static fn (?float $a): bool => $a !== null);
        arsort($ranked);

        $ranks = [];
        $position = 0;
        $previousAverage = null;
        $processed = 0;

        foreach ($ranked as $studentId => $average) {
            $processed++;

            if ($previousAverage === null || $average < $previousAverage) {
                $position = $processed;
                $previousAverage = $average;
            }

            $ranks[$studentId] = $position;
        }

        $totalStudents = count($ranked);
        $now = date('Y-m-d H:i:s');

        foreach ($averages as $studentId => $average) {
            if ($average === null) {
                continue;
            }

            $existing = $this->db->scalar(
                'SELECT id FROM period_results
                 WHERE tenant_id = :tenant AND student_id = :student AND period_id = :period',
                [
                    'tenant' => $this->tenant->requireId(),
                    'student' => $studentId,
                    'period' => $periodId,
                ]
            );

            $payload = [
                'average' => $average,
                'rank' => $ranks[$studentId] ?? null,
                'total' => $totalStudents,
                'mention' => self::mention($average),
                'classroom' => $classroomId,
                'updated_at' => $now,
            ];

            if ($existing !== null) {
                $this->db->execute(
                    'UPDATE period_results
                     SET general_average = :average, rank_position = :rank, total_students = :total,
                         mention = :mention, classroom_id = :classroom, updated_at = :updated_at
                     WHERE id = :id',
                    $payload + ['id' => $existing]
                );

                continue;
            }

            $this->db->execute(
                'INSERT INTO period_results
                    (id, tenant_id, student_id, period_id, classroom_id, general_average,
                     rank_position, total_students, mention, is_published, created_at, updated_at)
                 VALUES (:id, :tenant, :student, :period, :classroom, :average,
                     :rank, :total, :mention, :published, :created_at, :updated_at)',
                $payload + [
                    'id' => Table::uuid(),
                    'tenant' => $this->tenant->requireId(),
                    'student' => $studentId,
                    'period' => $periodId,
                    'published' => 0,
                    'created_at' => $now,
                ]
            );
        }
    }

    /**
     * Mention correspondant a une moyenne sur 20, bareme camerounais usuel.
     */
    public static function mention(float $average): string
    {
        if ($average >= 16) {
            return 'Tres bien';
        }

        if ($average >= 14) {
            return 'Bien';
        }

        if ($average >= 12) {
            return 'Assez bien';
        }

        if ($average >= 10) {
            return 'Passable';
        }

        return 'Insuffisant';
    }
}
