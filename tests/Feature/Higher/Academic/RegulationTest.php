<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Academic;

use Scholaris\Modules\Apps\Higher\Academic\Application\RegulationService;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\RegulationBook;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\Higher\Academic\AcademicTestCase;

/**
 * EX-ACA-05, RG-ACA-3 (T216, T218) : règlement des études par régime et école, figé à l'ouverture des inscriptions ; une
 * modification exige le directeur académique (R03) avec une double authentification récente, et elle est tracée.
 * EX-ACA-06 : calendrier académique par école et par année.
 */
final class RegulationTest extends AcademicTestCase
{
    /** @var array{school: string, department: string, program: string, l1: string, l2: string, g1: string} */
    private array $org;

    protected function setUp(): void
    {
        $this->bootAcademic();
        $this->org = $this->buildOrg();
        $this->assign(self::T, 'dir-acad', self::E, 'higher.regulation.amend', 'establishment', null, 'higher_dir_academique');
    }

    /** @return array<string, mixed> */
    private function regulation(): array
    {
        return $this->ok('POST', '/higher/regulations', [
            'school_id' => $this->org['school'], 'regime' => 'lmd', 'academic_year_id' => self::YEAR, 'parameters' => ['eliminatory_unit_mark' => 7.5],
        ]);
    }

    public function testValeursParDefautDuCdcEtSurcharges(): void
    {
        $regulation = $this->regulation();
        $p = $regulation['parameters'];

        self::assertSame(['draft', 1], [$regulation['status'], $regulation['version']]);
        self::assertSame([40, 2, 10, true, 7.5, true, true, 60, 45, 30, 0.5], [
            $p['cc_weight_percent'], $p['rounding_decimals'], $p['unit_pass_mark'], $p['compensation_enabled'], $p['eliminatory_unit_mark'],
            $p['resit_enabled'], $p['resit_replaces_exam'], $p['progression_admitted_credits'], $p['progression_debts_min_credits'],
            $p['credits_per_semester'], $p['jury_points_max'],
        ]);
        self::assertSame(409, $this->call('POST', '/higher/regulations', ['school_id' => $this->org['school'], 'regime' => 'lmd', 'academic_year_id' => self::YEAR])[0]);
        [$status, $problem] = $this->call('POST', '/higher/regulations', ['school_id' => $this->org['school'], 'regime' => 'bts', 'academic_year_id' => self::YEAR, 'parameters' => ['formule_magique' => 1, 'cc_weight_percent' => 140]]);
        self::assertSame(422, $status);
        self::assertSame(['parameters.formule_magique', 'parameters.cc_weight_percent'], array_keys($problem['errors']));

        $bts = $this->ok('POST', '/higher/regulations', ['school_id' => $this->org['school'], 'regime' => 'bts', 'academic_year_id' => self::YEAR]);
        self::assertSame([10, 70], [$bts['parameters']['annual_pass_average'], $bts['parameters']['national_exam_min_attendance_percent']]);
    }

    public function testRg_aca_3_ReglementFigeModifiableSeulementParR03Avec2fa(): void
    {
        $regulation = $this->regulation();
        $id = $regulation['id'];
        self::assertEquals(7, $this->ok('PATCH', '/higher/regulations/' . $id, ['parameters' => ['eliminatory_unit_mark' => 7]])['parameters']['eliminatory_unit_mark'], 'Brouillon modifiable.');

        self::assertSame('frozen', $this->ok('POST', '/higher/regulations/' . $id . '/freeze')['status']);
        [$status, $problem] = $this->call('PATCH', '/higher/regulations/' . $id, ['parameters' => ['eliminatory_unit_mark' => 8]]);
        self::assertSame(409, $status);
        self::assertStringContainsString('RG-ACA-3', (string) $problem['detail']);

        $this->assign(self::T, 'dir-ecole', self::E, 'higher.settings.manage', Scope::ORG_UNIT, $this->org['school']);
        self::assertSame(403, $this->call('POST', '/higher/regulations/' . $id . '/amend', ['parameters' => ['eliminatory_unit_mark' => 8], 'reason' => 'Règlement de la tutelle'], 'dir-ecole')[0], 'Pas le directeur académique.');

        [$status, $problem] = $this->call('POST', '/higher/regulations/' . $id . '/amend', ['parameters' => ['eliminatory_unit_mark' => 8], 'reason' => 'Règlement de la tutelle'], 'dir-acad');
        self::assertSame([403, RegulationService::SECOND_FACTOR_TYPE], [$status, $problem['type']], 'Double authentification récente exigée.');
        self::assertSame(422, $this->call('POST', '/higher/regulations/' . $id . '/amend', ['parameters' => ['eliminatory_unit_mark' => 8]], 'dir-acad')[0], 'Motif obligatoire.');

        $this->secondFactor->validate(self::T, 'dir-acad');
        $amended = $this->ok('POST', '/higher/regulations/' . $id . '/amend', ['parameters' => ['eliminatory_unit_mark' => 8], 'reason' => 'Règlement de la tutelle 2026'], 'dir-acad');
        self::assertEquals(['frozen', 2, 8], [$amended['status'], $amended['version'], $amended['parameters']['eliminatory_unit_mark']]);

        $revisions = $this->call('GET', '/higher/regulations/' . $id . '/revisions')[1]['data'];
        self::assertCount(1, $revisions);
        self::assertEquals(['dir-acad', 'Règlement de la tutelle 2026', 7, 8], [$revisions[0]['amended_by'], $revisions[0]['reason'], $revisions[0]['previous_parameters']['eliminatory_unit_mark'], $revisions[0]['new_parameters']['eliminatory_unit_mark']]);
        $actions = array_column($this->audit->entries, 'action');
        self::assertContains('higher.regulation.amend_refused', $actions);
        self::assertContains('higher.regulation.amended', $actions);
    }

    public function testFigeAOuvertureDesInscriptionsParContrat(): void
    {
        $regulation = $this->regulation();
        $book = $this->service(RegulationBook::class);

        self::assertNull($book->freezeForEnrolment(self::T, self::E, $this->org['school'], 'hnd', self::YEAR));
        $frozen = $book->freezeForEnrolment(self::T, self::E, $this->org['school'], 'lmd', self::YEAR, 'scolarite');
        self::assertTrue($frozen?->isFrozen());
        self::assertSame($regulation['id'], $book->effective(self::T, self::E, $this->org['school'], 'lmd', self::YEAR)?->id);
        self::assertSame(409, $this->call('POST', '/higher/regulations/' . $regulation['id'] . '/freeze')[0]);
        self::assertSame(1, count($this->call('GET', '/higher/regulations?filter[status]=frozen')[1]['data']));
    }

    public function testCalendrierAcademiqueParEcoleEtAnnee(): void
    {
        $entries = [
            ['semester', 'Semestre 1', '2026-10-05', '2027-01-15', 1],
            ['exam_period', 'Examens S1', '2027-01-18', '2027-01-29', 1],
            ['resit_period', 'Rattrapage', '2027-06-21', '2027-06-30', null],
            ['deliberation', 'Délibérations annuelles', '2027-07-05', '2027-07-09', null],
            ['holiday', 'Fête nationale', '2027-05-20', '2027-05-20', null],
        ];
        foreach ($entries as [$kind, $label, $from, $to, $semester]) {
            $this->ok('POST', '/higher/calendar-entries', [
                'school_id' => $this->org['school'], 'academic_year_id' => self::YEAR, 'entry_kind' => $kind, 'label' => $label,
                'starts_on' => $from, 'ends_on' => $to, 'semester_ordinal' => $semester,
            ]);
        }
        $list = $this->call('GET', '/higher/calendar-entries?filter[academic_year_id]=' . self::YEAR)[1]['data'];
        self::assertSame(['Semestre 1', 'Examens S1', 'Fête nationale', 'Rattrapage', 'Délibérations annuelles'], array_column($list, 'label'));

        self::assertSame(422, $this->call('POST', '/higher/calendar-entries', ['school_id' => $this->org['school'], 'academic_year_id' => self::YEAR, 'entry_kind' => 'semester', 'label' => 'S2', 'starts_on' => '2027-02-01', 'ends_on' => '2027-05-31'])[0], 'Numéro de semestre requis.');
        self::assertSame(422, $this->call('PATCH', '/higher/calendar-entries/' . $list[0]['id'], ['ends_on' => '2026-01-01'])[0]);
        self::assertSame('2027-01-22', $this->ok('PATCH', '/higher/calendar-entries/' . $list[1]['id'], ['ends_on' => '2027-01-22'])['ends_on']);
        $this->ok('DELETE', '/higher/calendar-entries/' . $list[2]['id']);
        self::assertCount(4, $this->call('GET', '/higher/calendar-entries')[1]['data']);
    }
}
