<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Application\AcademicClock;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherBtsHndStrategy;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\BtsHndCalculator;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\LmdCalculator;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\Marks;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/**
 * Préparation du jury : simulation sans écriture des résultats (API-13, EX-DEL-02 : décisions proposées, cas à moins de 0,5 point
 * d'un seuil, points de jury pris en compte) et liste des convoqués au rattrapage par EC (EX-DEL-04, NOT-13).
 */
final class PreparationService
{
    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly JuryService $juries,
        private readonly RulesProvider $rules,
        private readonly MarkSources $marks,
        private readonly StudentDirectory $students,
        private readonly Messenger $messenger,
        private readonly AcademicClock $clock,
        private readonly LmdCalculator $lmd = new LmdCalculator(),
        private readonly BtsHndCalculator $bts = new BtsHndCalculator(),
    ) {
    }

    /**
     * @param array<string, mixed> $overrides options de règles à essayer (ex. eliminatory_unit_mark), sans rien publier
     * @return array<string, mixed>
     */
    public function simulate(Principal $principal, string $establishmentId, string $juryId, array $overrides = [], float $margin = 0.5): array
    {
        $tenantId = $principal->tenantId;
        $jury = $this->juries->writable($principal, $establishmentId, $juryId, [DeliberationPermissions::PREPARE]);
        $sheet = $this->marks->require($tenantId, $establishmentId, (string) $jury['structure_id'], (string) $jury['period_id'], (string) $jury['exam_session']);
        $rules = $this->withOverrides($this->rules->forJury($jury), $overrides);
        $points = $this->juries->pointsOf($tenantId, $establishmentId, $juryId);
        $names = $this->juries->names($tenantId, array_keys($sheet->units));
        $isBts = $rules->strategy === HigherBtsHndStrategy::KEY;

        $rows = [];
        $averages = [];
        foreach ($sheet->units as $personId => $units) {
            $result = $isBts
                ? $this->bts->semester($rules, $units, (string) $jury['exam_session'])
                : $this->lmd->semester($rules, $units, (string) $jury['exam_session'], $points[$personId] ?? 0.0);
            $averages[$personId] = $result['average'];
            $rows[$personId] = [
                'person_id' => $personId, 'name' => $names[$personId] ?? null, 'matricule' => $this->students->student($tenantId, $establishmentId, $personId)?->matricule,
                'average' => $result['average'], 'raw_average' => $result['extras']['raw_average'] ?? $result['average'],
                'jury_points' => $result['extras']['jury_points'] ?? 0.0, 'gpa' => $result['extras']['gpa'] ?? null,
                'credits_earned' => $result['credits_earned'], 'credits_total' => $result['credits_total'], 'mention' => $result['mention'],
                'proposal' => $result['deliberation_proposal'], 'proposal_code' => $result['extras']['proposal_code'] ?? null,
                'units' => array_map(static fn (string $id, array $u): array => ['subject_id' => $id] + $u, array_keys($result['units'] ?? []), $result['units'] ?? []),
                'subjects' => $result['subjects'],
                'borderline' => $isBts ? $this->btsBorderline($rules, $result['average'], $margin) : $this->lmd->borderline($rules, $result, $margin),
            ];
        }
        $ranks = Marks::ranks($averages);
        $rows = array_map(static fn (array $row): array => $row + ['rank' => $ranks[$row['person_id']] ?? null], array_values($rows));
        usort($rows, static fn (array $a, array $b): int => [(string) $a['name'], $a['person_id']] <=> [(string) $b['name'], $b['person_id']]);
        if ($jury['status'] === 'prepared') {
            $this->store->update(JuryService::TABLE, $tenantId, $establishmentId, ['status' => 'simulated', 'updated_at' => $this->clock->now()], ['id' => $juryId]);
        }

        $byProposal = [];
        foreach ($rows as $row) {
            $byProposal[(string) $row['proposal']] = ($byProposal[(string) $row['proposal']] ?? 0) + 1;
        }

        return [
            'jury_id' => $juryId,
            'rules' => ['strategy' => $rules->strategy, 'version' => $rules->version, 'options' => $rules->options],
            'students' => $rows,
            'summary' => [
                'students' => count($rows), 'by_proposal' => $byProposal,
                'borderline' => count(array_filter($rows, static fn (array $r): bool => $r['borderline'] !== [])),
            ],
            'unlocked' => $sheet->unlocked,
            'margin' => $margin,
        ];
    }

    /**
     * Convoqués au rattrapage par EC (session normale) : EC sous la note de validation dans une UE non acquise.
     *
     * @return list<array<string, mixed>>
     */
    public function resitCandidates(Principal $principal, string $establishmentId, string $juryId): array
    {
        $tenantId = $principal->tenantId;
        $jury = $this->juries->readable($principal, $establishmentId, $juryId, [DeliberationPermissions::PREPARE, DeliberationPermissions::PRESIDE]);
        if ($jury['exam_session'] !== 'normal') {
            throw HttpException::conflict('La liste du rattrapage se tire du jury de la session normale.');
        }
        $stored = $this->store->select('hi_resit_convocations', $tenantId, $establishmentId, ['jury_id' => $juryId], 'element_code, person_id');
        if ($stored !== []) {
            return $this->group($tenantId, $stored);
        }

        return $this->group($tenantId, $this->compute($jury));
    }

    /**
     * Enregistre la liste (remplace la précédente) et, sur demande, convoque chaque étudiant (NOT-13, SMS et e-mail).
     *
     * @return array{elements: list<array<string, mixed>>, notified: int}
     */
    public function convokeResit(Principal $principal, string $establishmentId, string $juryId, bool $notify): array
    {
        $tenantId = $principal->tenantId;
        $jury = $this->juries->writable($principal, $establishmentId, $juryId, [DeliberationPermissions::PREPARE]);
        if ($jury['exam_session'] !== 'normal') {
            throw HttpException::conflict('La liste du rattrapage se tire du jury de la session normale.');
        }
        $rows = $this->compute($jury);
        $now = $this->clock->now();
        $notified = 0;
        $this->store->transaction(function () use ($tenantId, $establishmentId, $juryId, $rows, $now): void {
            $this->store->delete('hi_resit_convocations', $tenantId, $establishmentId, ['jury_id' => $juryId]);
            foreach ($rows as $row) {
                $this->store->insert('hi_resit_convocations', $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'jury_id' => $juryId, 'person_id' => $row['person_id'], 'unit_code' => $row['unit_code'],
                    'element_code' => $row['element_code'], 'note' => $row['note'], 'notified_at' => null, 'created_at' => $now,
                ]);
            }
        });
        if ($notify) {
            $byPerson = [];
            foreach ($rows as $row) {
                $byPerson[$row['person_id']][] = $row['element_code'];
            }
            foreach ($byPerson as $personId => $codes) {
                $this->messenger->send($tenantId, $establishmentId, (string) $personId, DeliberationPermissions::NOT13, [
                    'subject' => implode(', ', $codes), 'label' => (string) $jury['period_label'], 'school_year' => (string) $jury['academic_year_id'],
                ], ['sms', 'email']);
                $this->store->update('hi_resit_convocations', $tenantId, $establishmentId, ['notified_at' => $now], ['jury_id' => $juryId, 'person_id' => (string) $personId]);
                ++$notified;
            }
        }
        $this->access->audit($principal, 'higher.deliberation.resit_list', 'jury', $juryId, null, ['convocations' => count($rows), 'notified' => $notified]);

        return ['elements' => $this->group($tenantId, $this->store->select('hi_resit_convocations', $tenantId, $establishmentId, ['jury_id' => $juryId], 'element_code, person_id')), 'notified' => $notified];
    }

    /**
     * @param array<string, mixed> $jury
     * @return list<array{person_id: string, unit_code: string, element_code: string, note: float|null}>
     */
    private function compute(array $jury): array
    {
        $tenantId = (string) $jury['tenant_id'];
        $establishmentId = (string) $jury['establishment_id'];
        $sheet = $this->marks->require($tenantId, $establishmentId, (string) $jury['structure_id'], (string) $jury['period_id'], 'normal');
        $rules = $this->rules->forJury($jury);
        $unitPass = (float) ($rules->options['unit_pass_mark'] ?? 10.0);
        $points = $this->juries->pointsOf($tenantId, $establishmentId, (string) $jury['id']);
        $out = [];
        foreach ($sheet->units as $personId => $units) {
            $result = $rules->strategy === HigherBtsHndStrategy::KEY
                ? $this->bts->semester($rules, $units, 'normal')
                : $this->lmd->semester($rules, $units, 'normal', $points[$personId] ?? 0.0);
            foreach ($result['units'] ?? [] as $unit) {
                if (!in_array($unit['status'] ?? null, [LmdCalculator::NOT_ACQUIRED, 'failed'], true)) {
                    continue;
                }
                $elements = $unit['elements'] ?? [['code' => $unit['code'], 'note' => null]];
                foreach ($elements as $element) {
                    $note = $element['note'] ?? null;
                    if ($note === null || $note < $unitPass) {
                        $out[] = ['person_id' => (string) $personId, 'unit_code' => (string) $unit['code'], 'element_code' => (string) ($element['code'] ?? $unit['code']), 'note' => $note === null ? null : (float) $note];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function group(string $tenantId, array $rows): array
    {
        $names = $this->juries->names($tenantId, array_map(static fn (array $r): string => (string) $r['person_id'], $rows));
        $out = [];
        foreach ($rows as $row) {
            $code = (string) $row['element_code'];
            $out[$code] ??= ['element_code' => $code, 'unit_code' => (string) $row['unit_code'], 'students' => []];
            $out[$code]['students'][] = [
                'person_id' => (string) $row['person_id'], 'name' => $names[(string) $row['person_id']] ?? null,
                'note' => $row['note'] === null ? null : (float) $row['note'], 'notified_at' => $row['notified_at'] ?? null,
            ];
        }
        ksort($out);

        return array_values($out);
    }

    /** @param array<string, mixed> $overrides */
    private function withOverrides(CalculationRules $rules, array $overrides): CalculationRules
    {
        if ($overrides === []) {
            return $rules;
        }

        return new CalculationRules(
            $rules->version,
            $rules->strategy,
            $rules->scale,
            $rules->passMark,
            $rules->rounding,
            $rules->mentions,
            $rules->compensationFrom,
            $rules->capitalizeFrom,
            $rules->resitFrom,
            $overrides + $rules->options,
            $rules->decisionLabels,
            $rules->passingDecisions,
            $rules->absenceEffects,
        );
    }

    /** @return list<array{kind: string, unit: string|null, value: float, threshold: float, gap: float}> */
    private function btsBorderline(CalculationRules $rules, ?float $average, float $margin): array
    {
        $pass = (float) ($rules->options['annual_pass_average'] ?? 10.0);
        if ($average === null || $average >= $pass || $pass - $average > $margin + 1e-9) {
            return [];
        }

        return [['kind' => 'semester', 'unit' => null, 'value' => $average, 'threshold' => $pass, 'gap' => $rules->round($pass - $average)]];
    }
}
