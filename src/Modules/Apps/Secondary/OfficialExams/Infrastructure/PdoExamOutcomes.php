<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\OfficialExams\Infrastructure;

use Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts\ExamOutcomes;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Sessions de l'année puis comptes de leurs candidats par code de résultat (deux requêtes). */
final class PdoExamOutcomes implements ExamOutcomes
{
    public function __construct(private readonly Store $store)
    {
    }

    public function forYear(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        $sessions = $this->store->select('se_official_exam_sessions', $tenantId, $establishmentId, ['academic_year_id' => $academicYearId], 'exam_code, id', 500);
        if ($sessions === []) {
            return [];
        }
        $ids = array_map(static fn (array $s): string => (string) $s['id'], $sessions);
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->store->pdo()->prepare(
            'SELECT session_id, result_code, COUNT(*) AS total FROM se_official_exam_candidates WHERE tenant_id = ? AND establishment_id = ? AND session_id IN (' . $marks . ')'
            . ' GROUP BY session_id, result_code',
        );
        $statement->execute([$tenantId, $establishmentId, ...$ids]);
        /** @var array<string, array<string, int>> $counts */
        $counts = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['session_id']][(string) ($row['result_code'] ?? '')] = (int) $row['total'];
        }
        $out = [];
        foreach ($sessions as $session) {
            $codes = json_decode((string) $session['passing_codes'], true);
            $passing = is_array($codes) ? array_map(static fn (mixed $c): string => is_scalar($c) ? (string) $c : '', $codes) : [];
            $candidates = 0;
            $results = 0;
            $passed = 0;
            foreach ($counts[(string) $session['id']] ?? [] as $code => $total) {
                $candidates += $total;
                if ((string) $code !== '') {
                    $results += $total;
                    $passed += in_array((string) $code, $passing, true) ? $total : 0;
                }
            }
            $out[] = [
                'session_id' => (string) $session['id'], 'exam_code' => (string) $session['exam_code'], 'exam_label' => (string) $session['exam_label'],
                'class_structure_id' => (string) $session['class_structure_id'], 'candidates' => $candidates, 'results' => $results, 'passed' => $passed,
            ];
        }

        return $out;
    }
}
