<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Application;

use Scholaris\Modules\Apps\PublicPrimary\Exams\Infrastructure\PdoExams;
use Scholaris\Modules\Socle\Reporting\Contracts\TerritoryAggregateProvider;

/**
 * Indicateur territorial « résultats aux examens » (CDC, Indicateurs : taux de réussite au CEP ou au FSLC = admis ÷ candidats présents ;
 * objectif O5 : candidats rejetés pour état civil manquant). Cases `exam=<code>;result=passed|failed|absent|blocked` pour l'année
 * scolaire de la date de référence ; résultats PUBLIÉS seulement ; aucun nom.
 */
final class ExamAggregates implements TerritoryAggregateProvider
{
    public function __construct(private readonly PdoExams $repository)
    {
    }

    public function indicator(): string
    {
        return 'public_primary.exam_results';
    }

    public function countsFor(string $tenantId, string $establishmentId, string $referenceDate): array
    {
        $year = (int) substr($referenceDate, 0, 4);
        $schoolYear = (int) substr($referenceDate, 5, 2) >= 9 ? $year . '-' . ($year + 1) : ($year - 1) . '-' . $year;
        $sessions = [];
        foreach ($this->repository->sessions($tenantId, $schoolYear) as $session) {
            $sessions[(string) $session['id']] = (string) $session['exam_code'];
        }
        $counts = [];
        foreach ($this->repository->candidatesOfSchool($tenantId, $establishmentId) as $row) {
            $code = $sessions[(string) $row['session_id']] ?? null;
            if ($code === null) {
                continue;
            }
            $result = match (true) {
                $row['candidate_state'] === 'blocked' => 'blocked',
                $row['candidate_state'] === 'validated' && $row['result_published_at'] !== null && is_string($row['outcome']) => (string) $row['outcome'],
                default => null,
            };
            if ($result !== null) {
                $cell = 'exam=' . $code . ';result=' . $result;
                $counts[$cell] = ($counts[$cell] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }
}
