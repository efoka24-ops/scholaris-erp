<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\OfficialExams\Infrastructure;

use Scholaris\Modules\Apps\Secondary\OfficialExams\Contracts\ExamCandidateDirectory;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Domain\ExamRules;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Sessions ouvertes dont la date limite d'inscription tombe dans l'intervalle, avec leurs candidats (identifiants de personne). */
final class PdoExamCandidateDirectory implements ExamCandidateDirectory
{
    public function __construct(private readonly Store $store)
    {
    }

    public function sessionsWithDeadlineBetween(string $tenantId, string $establishmentId, string $from, string $to): array
    {
        $out = [];
        $sessions = $this->store->select('se_official_exam_sessions', $tenantId, $establishmentId, ['status' => ExamRules::SESSION_OPEN], 'registration_deadline', 500, 'registration_deadline IS NOT NULL AND registration_deadline >= ? AND registration_deadline <= ?', [$from, $to]);
        foreach ($sessions as $session) {
            $candidates = $this->store->select('se_official_exam_candidates', $tenantId, $establishmentId, ['session_id' => (string) $session['id']], 'full_name', 5000);
            $out[] = [
                'session_id' => (string) $session['id'], 'exam_code' => (string) $session['exam_code'], 'exam_label' => (string) $session['exam_label'],
                'registration_deadline' => (string) $session['registration_deadline'],
                'person_ids' => array_values(array_unique(array_map(static fn (array $c): string => (string) $c['person_id'], $candidates))),
            ];
        }

        return $out;
    }
}
