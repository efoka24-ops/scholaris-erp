<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Academic;

/**
 * Base des tests de Curriculum : organisation, autorisation et tutelle de l'année, puis maquette L1 S1 de l'exemple du CDC
 * (Marie N., Comptabilité et finance : 5 UE de 6 crédits, 9 EC, 30 crédits).
 */
abstract class CurriculumTestCase extends AcademicTestCase
{
    /** UE => [type, [EC => crédits]] (cdc-ipes.md, exemple L1 S1). */
    public const MARIE_S1 = [
        'UE1' => ['fundamental', ['CG' => [4, 'Comptabilité générale'], 'TDC' => [2, 'TD de comptabilité']]],
        'UE2' => ['fundamental', ['MF' => [6, 'Mathématiques financières']]],
        'UE3' => ['fundamental', ['MIC' => [3, 'Microéconomie'], 'MAC' => [3, 'Macroéconomie']]],
        'UE4' => ['transversal', ['DC' => [3, 'Droit civil'], 'IA' => [3, 'Institutions administratives']]],
        'UE5' => ['transversal', ['ANG' => [3, 'Anglais'], 'BUR' => [3, 'Bureautique']]],
    ];

    /** @var array{school: string, department: string, program: string, l1: string, l2: string, g1: string} */
    protected array $org;

    protected function bootCurriculum(string $regime = 'lmd'): void
    {
        $this->bootAcademic();
        $this->org = $this->buildOrg($regime);
        $this->authorize($this->org['program'], null);
        if ($regime === 'lmd') {
            $this->tutelage($this->org['program']);
        }
    }

    /** @return array<string, mixed> maquette en brouillon, un seul semestre (S1) */
    protected function draft(string $year = self::YEAR, ?string $approvedRef = 'UD/FSEG/LIC-CF/2024'): array
    {
        return $this->ok('POST', '/higher/curricula', [
            'program_id' => $this->org['program'], 'level_id' => $this->org['l1'], 'academic_year_id' => $year,
            'approved_programme_ref' => $approvedRef, 'semester_ordinals' => [1],
        ]);
    }

    /**
     * Maquette Marie N. S1 (30 crédits) ; volumes : 1 crédit = 25 h (CM 10 h + TD 5 h par crédit, 10 h de travail personnel).
     *
     * @return array{id: string, units: array<string, string>, elements: array<string, string>}
     */
    protected function marie(string $year = self::YEAR): array
    {
        $curriculum = $this->draft($year);
        $units = $elements = [];
        foreach (self::MARIE_S1 as $code => [$type, $ecs]) {
            $credits = array_sum(array_map(static fn (array $ec): int => $ec[0], $ecs));
            $units[$code] = (string) $this->ok('POST', '/higher/curricula/' . $curriculum['id'] . '/units', [
                'semester_ordinal' => 1, 'code' => $code, 'title' => 'Unité ' . $code, 'unit_type' => $type, 'credits' => $credits, 'coefficient' => $credits,
            ])['id'];
            foreach ($ecs as $ecCode => [$ecCredits, $title]) {
                $elements[$ecCode] = (string) $this->ok('POST', '/higher/curriculum-units/' . $units[$code] . '/elements', [
                    'code' => $ecCode, 'title' => $title, 'credits' => $ecCredits, 'coefficient' => $ecCredits,
                    'cm_hours' => 10 * $ecCredits, 'td_hours' => 5 * $ecCredits, 'tp_hours' => 0, 'personal_hours' => 10 * $ecCredits,
                ])['id'];
            }
        }

        return ['id' => (string) $curriculum['id'], 'units' => $units, 'elements' => $elements];
    }
}
