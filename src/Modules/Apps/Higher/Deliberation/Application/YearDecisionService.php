<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Application\AcademicClock;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherBtsHndStrategy;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\BtsHndCalculator;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\LmdCalculator;
use Scholaris\Modules\Apps\Higher\Deliberation\Infrastructure\PdoDeliberationRecords;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\ProgressionDecisionRecorder;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;

/**
 * Décisions de fin d'année (EX-DEL-07, F4, F6) à partir des résultats PUBLIÉS des semestres de l'année (session de rattrapage si
 * elle a eu lieu) : admis, admis avec dettes, redouble (UE capitalisées), exclu pour insuffisance (RG-INS-5, sauf dérogation),
 * dossier de diplôme en dernière année avec 180 crédits cumulés ; mention. L'enregistrement exige les PV signés de chaque semestre
 * et transmet la décision à l'inscription (RG-INS-3, RG-INS-4 : UE acquises et dettes) par ProgressionDecisionRecorder.
 */
final class YearDecisionService
{
    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly AcademicDirectory $academic,
        private readonly StudentDirectory $students,
        private readonly ResultsDirectory $results,
        private readonly RulesProvider $rules,
        private readonly ProgressionDecisionRecorder $recorder,
        private readonly AcademicClock $clock,
        private readonly LmdCalculator $lmd = new LmdCalculator(),
        private readonly BtsHndCalculator $bts = new BtsHndCalculator(),
    ) {
    }

    /**
     * @param list<string> $periodIds semestres de l'année
     * @param list<string> $derogations personnes bénéficiant d'une dérogation (RG-INS-5, directeur d'école)
     * @return list<array<string, mixed>>
     */
    public function decide(Principal $principal, string $establishmentId, string $structureId, string $academicYearId, array $periodIds, array $derogations, bool $record): array
    {
        $tenantId = $principal->tenantId;
        $group = $this->academic->group($tenantId, $establishmentId, $structureId) ?? throw HttpException::notFound('Groupe introuvable.');
        $program = $this->academic->program($tenantId, $establishmentId, $group->programId) ?? throw HttpException::notFound('Filière introuvable.');
        $level = $this->academic->level($tenantId, $establishmentId, $group->levelId);
        $scope = ['school' => $program->schoolId, 'program' => $program->id, 'level' => $group->levelId, 'group' => $group->id];
        $permissions = $record ? [DeliberationPermissions::PRESIDE, DeliberationPermissions::PREPARE] : [DeliberationPermissions::READ, DeliberationPermissions::PREPARE];
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $record ? $this->access->assertWritable($principal, $perimeter, $scope, 'year_decision', $structureId) : $this->access->assertReadable($perimeter, $scope);

        $juries = [];
        foreach ($periodIds as $periodId) {
            $rows = $this->store->select(JuryService::TABLE, $tenantId, $establishmentId, ['structure_id' => $structureId, 'period_id' => $periodId], 'exam_session DESC');
            $signed = array_values(array_filter($rows, static fn (array $j): bool => in_array($j['status'], PdoDeliberationRecords::SIGNED, true)));
            if ($signed === []) {
                throw HttpException::conflict(sprintf('Le procès-verbal du semestre %s n\'est pas signé : décision de fin d\'année impossible (RG-DEL-3).', $periodId));
            }
            $juries[$periodId] = $signed[0];
        }
        $first = reset($juries) ?: throw HttpException::validation(['period_ids' => ['Indiquez les semestres de l\'année.']]);
        $rules = $this->rules->forJury($first);
        $isBts = $rules->strategy === HigherBtsHndStrategy::KEY;
        $finalYear = !$isBts && $level !== null && $level->isFinal;

        $out = [];
        foreach ($this->students->enrolledIn($tenantId, $establishmentId, $structureId, $academicYearId) as $enrolment) {
            $periods = [];
            foreach ($juries as $periodId => $jury) {
                $periods[] = $this->period($tenantId, $establishmentId, $enrolment->personId, $periodId);
            }
            $previous = $this->store->select('hi_unit_credits', $tenantId, $establishmentId, ['person_id' => $enrolment->personId, 'program_id' => $program->id], 'recorded_at', 1000);
            $previous = array_values(array_filter($previous, static fn (array $u): bool => $u['academic_year_id'] !== $academicYearId));
            $context = [
                'enrolments_at_level' => $enrolment->attemptNumber, 'derogation' => in_array($enrolment->personId, $derogations, true),
                'cumulative_credits' => array_sum(array_map(static fn (array $u): int => (int) $u['credits'], $previous)),
                'capitalized_units' => array_map(static fn (array $u): string => (string) $u['unit_code'], $previous), 'final_year' => $finalYear,
            ];
            $year = $isBts ? $this->bts->year($rules, $periods, $context) : $this->lmd->year($rules, $periods, $context);
            $extras = $year['extras'] ?? [];
            $row = [
                'person_id' => $enrolment->personId, 'matricule' => $enrolment->matricule, 'enrolment_id' => $enrolment->id, 'attempt_number' => $enrolment->attemptNumber,
                'average' => $year['average'], 'credits_earned' => $year['credits_earned'], 'credits_total' => $year['credits_total'],
                'cumulative_credits' => (int) ($extras['cumulative_credits'] ?? 0), 'decision_code' => (string) ($extras['decision_code'] ?? ''),
                'decision_label' => $year['decision'], 'mention' => $extras['mention'] ?? null, 'diploma_file' => ($extras['diploma_file'] ?? false) === true,
                'derogation' => $context['derogation'], 'acquired_units' => (array) ($extras['acquired_units'] ?? []), 'missing_units' => (array) ($extras['missing_units'] ?? []),
                'periods' => array_map(static fn (array $p): array => ['period_id' => $p['period_id'], 'average' => $p['average'], 'credits_earned' => $p['credits_earned'], 'credits_total' => $p['credits_total']], $periods),
            ];
            if ($record) {
                $this->record($principal, $establishmentId, $program->schoolId, $program->id, $group->levelId, $structureId, $academicYearId, $row);
            }
            $out[] = $row;
        }
        if ($record) {
            $this->access->audit($principal, 'higher.deliberation.year_decisions', 'structure', $structureId, null, ['academic_year_id' => $academicYearId, 'count' => count($out)]);
        }

        return $out;
    }

    /** @return array{period_id: string, average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>} */
    private function period(string $tenantId, string $establishmentId, string $personId, string $periodId): array
    {
        $chosen = null;
        foreach ($this->results->publishedFor($tenantId, $personId) as $result) {
            if ($result->periodId !== $periodId || $result->establishmentId !== $establishmentId) {
                continue;
            }
            if ($chosen === null || $result->session === 'resit') {
                $chosen = $result;
            }
        }
        if ($chosen === null) {
            return ['period_id' => $periodId, 'average' => null, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []];
        }
        $units = [];
        foreach ($chosen->details as $detail) {
            $units[(string) ($detail['code'] ?? $detail['subject_id'] ?? count($units))] = $detail;
        }

        return ['period_id' => $periodId, 'average' => $chosen->average, 'credits_earned' => (int) $chosen->creditsEarned, 'credits_total' => (int) $chosen->creditsTotal, 'units' => $units];
    }

    /** @param array<string, mixed> $row */
    private function record(Principal $principal, string $establishmentId, string $schoolId, string $programId, string $levelId, string $structureId, string $academicYearId, array $row): void
    {
        $tenantId = $principal->tenantId;
        $personId = (string) $row['person_id'];
        $code = (string) $row['decision_code'];
        $this->store->transaction(function () use ($tenantId, $establishmentId, $schoolId, $programId, $levelId, $structureId, $academicYearId, $row, $personId, $code, $principal): void {
            $where = ['person_id' => $personId, 'program_id' => $programId, 'academic_year_id' => $academicYearId];
            $this->store->delete('hi_year_decisions', $tenantId, $establishmentId, $where);
            $this->store->insert('hi_year_decisions', $tenantId, $establishmentId, $where + [
                'id' => (string) new Ulid(), 'school_id' => $schoolId, 'level_id' => $levelId, 'structure_id' => $structureId,
                'average' => $row['average'], 'credits_earned' => (int) $row['credits_earned'], 'credits_total' => (int) $row['credits_total'],
                'cumulative_credits' => (int) $row['cumulative_credits'], 'decision_code' => $code, 'decision_label' => (string) $row['decision_label'],
                'mention' => $row['mention'], 'diploma_file' => $row['diploma_file'] ? 1 : 0, 'derogation' => $row['derogation'] ? 1 : 0,
                'acquired_units' => json_encode($row['acquired_units'], JSON_THROW_ON_ERROR), 'missing_units' => json_encode($row['missing_units'], JSON_THROW_ON_ERROR),
                'decided_by' => $principal->userId, 'decided_at' => $this->clock->now(),
            ]);
            $this->recorder->record(
                $tenantId,
                $establishmentId,
                $personId,
                $programId,
                $levelId,
                $academicYearId,
                $code,
                array_values(array_map('strval', $row['acquired_units'])),
                array_values(array_map('strval', $row['missing_units'])),
                $principal->userId,
            );
        });
    }
}
