<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\NationalExams\Domain\CandidateLifecycle;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamsStore;

/**
 * Résultats publiés de l'examen national (EX-EXN-06) : import par numéro de candidat ou matricule, sur les seuls candidats
 * exportés ou convoqués de la session ; taux de réussite par session (spécialité et année) dans le périmètre.
 */
final class ResultService
{
    private const IMPORTABLE = [CandidateLifecycle::EXPORTED, CandidateLifecycle::CONVENED, CandidateLifecycle::RESULTED];

    public function __construct(
        private readonly NationalExamsStore $store,
        private readonly NationalExamsAccess $access,
        private readonly SessionService $sessions,
        private readonly ListJournal $journal,
        private readonly NationalExamsClock $clock,
    ) {
    }

    /**
     * @param list<array{key: string, result: ?string, mark: ?float, line: int}> $rows
     * @return array{imported: int, rejected: list<array{line: int, key: string, reason: string}>}
     */
    public function import(Principal $principal, string $establishmentId, string $sessionId, array $rows): array
    {
        $session = $this->sessions->writable($principal, $establishmentId, $sessionId);
        if ((int) $session['export_count'] === 0) {
            throw HttpException::conflict('Les résultats ne s\'importent qu\'après l\'export de la liste.');
        }
        $byKey = [];
        foreach ($this->store->select(CandidateService::TABLE, $principal->tenantId, $establishmentId, ['session_id' => $sessionId], 'id', 10000) as $candidate) {
            foreach (['candidate_number', 'matricule'] as $field) {
                if (is_string($candidate[$field]) && $candidate[$field] !== '') {
                    $byKey[$candidate[$field]] = $candidate;
                }
            }
        }
        $imported = 0;
        $rejected = [];
        $now = $this->clock->now();
        foreach ($rows as $row) {
            $candidate = $byKey[$row['key']] ?? null;
            $reason = match (true) {
                $row['key'] === '' || $candidate === null => 'Candidat inconnu dans cette session.',
                !in_array($candidate['status'], self::IMPORTABLE, true) => 'Candidat non exporté (statut ' . $candidate['status'] . ').',
                $row['result'] === null => 'Résultat attendu : passed, failed ou absent (admis, ajourné, absent).',
                default => null,
            };
            if ($reason !== null) {
                $rejected[] = ['line' => $row['line'], 'key' => $row['key'], 'reason' => (string) $reason];
                continue;
            }
            $this->store->update(CandidateService::TABLE, $principal->tenantId, $establishmentId, [
                'status' => CandidateLifecycle::RESULTED, 'result_code' => $row['result'], 'result_mark' => $row['mark'], 'result_imported_at' => $now, 'updated_at' => $now,
            ], ['id' => (string) $candidate['id']]);
            ++$imported;
        }
        $this->journal->record($principal, $session, null, 'results_imported', ['imported' => $imported, 'rejected' => count($rejected)]);

        return ['imported' => $imported, 'rejected' => $rejected];
    }

    /** @return array<string, mixed> */
    public function statistics(Principal $principal, string $establishmentId, string $sessionId): array
    {
        [$session] = $this->sessions->readable($principal, $establishmentId, $sessionId);

        return $this->summary($principal->tenantId, $establishmentId, $session);
    }

    /** @return list<array<string, mixed>> une ligne par session du périmètre (spécialité, type, année) */
    public function successRates(Principal $principal, string $establishmentId, ?string $academicYearId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, NationalExamsPermissions::READ);
        [$scope, $params] = $this->access->sqlFilter($perimeter);
        $where = $academicYearId === null ? [] : ['academic_year_id' => $academicYearId];
        $rates = [];
        foreach ($this->store->select(SessionService::TABLE, $principal->tenantId, $establishmentId, $where, 'academic_year_id, exam_type, specialty_label', 1000, $scope, $params) as $session) {
            $rates[] = $this->summary($principal->tenantId, $establishmentId, $session);
        }

        return $rates;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function summary(string $tenantId, string $establishmentId, array $session): array
    {
        $counts = array_fill_keys([...CandidateLifecycle::STATUSES, ...CandidateLifecycle::RESULTS], 0);
        foreach ($this->store->select(CandidateService::TABLE, $tenantId, $establishmentId, ['session_id' => (string) $session['id']], 'id', 10000) as $candidate) {
            ++$counts[(string) $candidate['status']];
            if (is_string($candidate['result_code']) && isset($counts[$candidate['result_code']])) {
                ++$counts[$candidate['result_code']];
            }
        }

        return [
            'session_id' => (string) $session['id'], 'exam_type' => (string) $session['exam_type'], 'specialty_label' => (string) $session['specialty_label'],
            'program_id' => (string) $session['program_id'], 'academic_year_id' => (string) $session['academic_year_id'], 'counts' => $counts,
            'success_rate' => CandidateLifecycle::successRate($counts),
        ];
    }
}
