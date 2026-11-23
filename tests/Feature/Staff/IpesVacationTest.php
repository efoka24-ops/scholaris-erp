<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentDraft;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentInfo;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter;
use Scholaris\Modules\Socle\Staff\Contracts\VacationCalculator;
use Tests\Support\Staff\ScriptedPayabilityCheck;
use Tests\Support\Staff\StaffTestCase;

/**
 * Extensions IPES du module Staff : HETD paramétrable (D14), taux par grade et niveau (D15), contrôles de payabilité enfichables
 * (D16), écriture des affectations avec EC et groupe (D17, D18). Exemple chiffré du CDC (TST-11) : assistant, Licence 2,
 * 18 h CM + 12 h TD + 6 h TP à 6 000 FCFA/HETD = 43,5 HETD = 261 000 FCFA brut.
 */
final class IpesVacationTest extends StaffTestCase
{
    private ScriptedPayabilityCheck $check;

    /** @param array<string, mixed> $settings */
    private function start(array $settings = [], bool $withCheck = false): void
    {
        $this->check = new ScriptedPayabilityCheck();
        $this->bootStaff($settings, $withCheck ? ['staff.vacation.payability_checks.test' => [$this->check]] : []);
        $this->check->assignments = fn (string $id): ?AssignmentInfo => $this->writer()->find('t1', 'e1', $id);
    }

    private function writer(): AssignmentWriter
    {
        return $this->kernel->container()->get(AssignmentWriter::class);
    }

    private function calculator(): VacationCalculator
    {
        return $this->kernel->container()->get(VacationCalculator::class);
    }

    /**
     * Vacataire « assistant » avec contrat actif ; taux assistant tous niveaux 5 000, Licence 2 : 6 000.
     *
     * @return array<string, mixed>
     */
    private function assistant(): array
    {
        $vac = $this->staff('p-prof', 'vacataire', ['category' => 'assistant', 'grade' => 'Assistant']);
        self::assertSame(200, $this->code('PUT', '/vacation-rates', body: ['category' => 'assistant', 'hourly_rate' => 5000]));
        self::assertSame(200, $this->code('PUT', '/vacation-rates', body: ['category' => 'assistant', 'level' => 'L2', 'hourly_rate' => 6000]));
        $this->activeContract((string) $vac['id']);

        return $vac;
    }

    /** 18 h CM, 12 h TD et 6 h TP validées en octobre, rattachées à l'affectation donnée. */
    private function tst11Hours(string $staffId, string $assignmentId): void
    {
        for ($day = 1; $day <= 6; ++$day) {
            $this->validatedHour($staffId, 'prof', '2026-10-0' . $day, 180, 'cm', ['assignment_id' => $assignmentId, 'slot_id' => 'cm' . $day]);
            $this->validatedHour($staffId, 'prof', '2026-10-0' . $day, 120, 'td', ['assignment_id' => $assignmentId, 'slot_id' => 'td' . $day]);
        }
        $this->validatedHour($staffId, 'prof', '2026-10-05', 180, 'tp', ['assignment_id' => $assignmentId, 'slot_id' => 'tp1']);
        $this->validatedHour($staffId, 'prof', '2026-10-06', 180, 'tp', ['assignment_id' => $assignmentId, 'slot_id' => 'tp2']);
    }

    /** @return array<string, mixed> */
    private function statement(string $staffId): array
    {
        [$response, $body] = $this->api('POST', '/vacation-statements', body: ['staff_id' => $staffId, 'period' => '2026-10']);
        self::assertSame(201, $response->getStatusCode(), json_encode($body));

        return $body['data'];
    }

    public function testExempleDuCdcParLeContratDeCalcul(): void
    {
        $this->start();
        $this->assistant();
        $calculator = $this->calculator();

        self::assertSame(['cm' => 1500, 'td' => 1000, 'tp' => 750, 'other' => 1000], $calculator->coefficients('t1', 'e1'));
        $hetd = $calculator->hetdMilli('t1', 'e1', ['cm' => 18 * 60, 'td' => 12 * 60, 'tp' => 6 * 60]);
        self::assertSame(43500, $hetd);
        self::assertSame(6000, $calculator->hourlyRate('t1', 'e1', 'assistant', 'L2'));
        self::assertSame(261000, $calculator->amount($hetd, (int) $calculator->hourlyRate('t1', 'e1', 'assistant', 'L2')));
        self::assertSame(5000, $calculator->hourlyRate('t1', 'e1', 'assistant', 'M1'), 'niveau sans taux : taux tous niveaux');
        self::assertSame(5000, $calculator->hourlyRate('t1', 'e1', 'assistant'));
        self::assertNull($calculator->hourlyRate('t1', 'e1', 'professeur', 'L2'));
        self::assertNull($calculator->hourlyRate('t2', 'e1', 'assistant', 'L2'), 'jamais le taux d\'un autre client');
    }

    public function testExempleDuCdcDeBoutEnBoutAvecLeNiveauDeLAffectation(): void
    {
        $this->start();
        $vac = $this->assistant();
        $assignment = $this->writer()->assign('t1', 'e1', new AssignmentDraft((string) $vac['id'], 'cm', 36 * 60, 'EC-COMPTA', 'L2-G1', 'L2'), 'admin');
        $this->tst11Hours((string) $vac['id'], $assignment);

        $statement = $this->statement((string) $vac['id']);

        self::assertSame([36 * 60, 43500, 6000, 261000], [$statement['minutes_total'], $statement['hetd_milli'], $statement['hourly_rate'], $statement['gross_amount']]);
        $hour = $this->api('GET', '/vacation-hours?filter[assignment_id]=' . $assignment)[1]['data'][0];
        self::assertSame('L2', $hour['level'], 'le niveau de l\'heure vient de l\'affectation');
    }

    public function testDesHeuresDeNiveauxDifferentsSontPayeesChacuneASonTaux(): void
    {
        $this->start();
        $vac = $this->assistant();
        $assignment = $this->writer()->assign('t1', 'e1', new AssignmentDraft((string) $vac['id'], 'cm', 36 * 60, 'EC-COMPTA', 'L2-G1', 'L2'), 'admin');
        $this->tst11Hours((string) $vac['id'], $assignment);
        // 2 h de TD en M1, niveau sans taux propre : 2 HETD au taux tous niveaux (5 000)
        $this->validatedHour((string) $vac['id'], 'prof', '2026-10-07', 120, 'td', ['level' => 'M1']);

        $statement = $this->statement((string) $vac['id']);

        self::assertSame([45500, 271000], [$statement['hetd_milli'], $statement['gross_amount']]);
        self::assertSame(5956, $statement['hourly_rate'], 'taux moyen pondéré affiché quand plusieurs taux s\'appliquent');
    }

    public function testLesCoefficientsHetdSontParametrables(): void
    {
        $this->start(['staff.hetd.cm' => 2]);
        $vac = $this->assistant();
        $assignment = $this->writer()->assign('t1', 'e1', new AssignmentDraft((string) $vac['id'], 'cm', 36 * 60, 'EC-COMPTA', null, 'L2'), 'admin');
        $this->tst11Hours((string) $vac['id'], $assignment);

        // CM 18 × 2 = 36 ; TD 12 ; TP 4,5 : 52,5 HETD × 6 000
        $statement = $this->statement((string) $vac['id']);
        self::assertSame([52500, 315000], [$statement['hetd_milli'], $statement['gross_amount']]);
        self::assertSame(['cm' => 2000, 'td' => 1000, 'tp' => 750, 'other' => 1000], $this->calculator()->coefficients('t1', 'e1'));
    }

    public function testUnCoefficientInvalideRetombeSurLeDefautDuSocle(): void
    {
        $this->start(['staff.hetd.cm' => 0, 'staff.hetd.td' => 'abc', 'staff.hetd.tp' => '0.5', 'staff.hetd.other' => 50]);

        self::assertSame(['cm' => 1500, 'td' => 1000, 'tp' => 500, 'other' => 1000], $this->calculator()->coefficients('t1', 'e1'));
    }

    public function testTauxParCategorieEtNiveau(): void
    {
        $this->start();
        $this->assistant();
        self::assertSame(200, $this->code('PUT', '/vacation-rates', body: ['category' => 'assistant', 'level' => 'L2', 'hourly_rate' => 6500]));

        $rates = $this->api('GET', '/vacation-rates')[1]['data'];
        self::assertSame([['assistant', null, 5000], ['assistant', 'L2', 6500]], array_map(static fn (array $r): array => [$r['category'], $r['level'], $r['hourly_rate']], $rates));
        self::assertSame(422, $this->code('PUT', '/vacation-rates', body: ['category' => 'assistant', 'level' => str_repeat('x', 41), 'hourly_rate' => 1]));
    }

    public function testLeContratDEcritureDesAffectations(): void
    {
        $this->start();
        $vac = $this->staff('p-prof', 'vacataire', ['category' => 'assistant']);
        $writer = $this->writer();
        $id = $writer->assign('t1', 'e1', new AssignmentDraft((string) $vac['id'], 'td', 720, 'EC-COMPTA', 'L1-G2', 'L1', subjectLabel: 'Comptabilité générale'), 'admin');
        $writer->assign('t1', 'e1', new AssignmentDraft((string) $vac['id'], 'cm', 1080, 'EC-COMPTA', null, 'L1'), 'admin');

        $info = $writer->find('t1', 'e1', $id);
        self::assertSame(['EC-COMPTA', 'L1-G2', 'L1', 'td', 720, 0, 'active'], [$info?->elementRef, $info?->groupRef, $info?->level, $info?->sessionKind, $info?->plannedMinutes, $info?->validatedMinutes, $info?->status]);
        self::assertCount(2, $writer->forElement('t1', 'e1', 'EC-COMPTA'));
        self::assertCount(1, $writer->forElement('t1', 'e1', 'EC-COMPTA', 'L1-G2'));
        self::assertSame([], $writer->forElement('t2', 'e1', 'EC-COMPTA'), 'jamais d\'un autre client');
        self::assertNull($writer->find('t1', 'e2', $id), 'jamais d\'un autre établissement');

        $writer->change('t1', 'e1', $id, ['planned_minutes' => 900, 'group_ref' => 'L1-G3']);
        self::assertSame([900, 'L1-G3'], [$writer->find('t1', 'e1', $id)?->plannedMinutes, $writer->find('t1', 'e1', $id)?->groupRef]);
        $writer->end('t1', 'e1', $id);
        self::assertSame('ended', $writer->find('t1', 'e1', $id)?->status);
        self::assertCount(1, $writer->forElement('t1', 'e1', 'EC-COMPTA'), 'une affectation terminée n\'est plus active');

        $api = $this->api('GET', '/staff/' . $vac['id'] . '/assignments')[1]['data'];
        self::assertSame('L1-G3', $api[0]['group_ref']);
        self::assertSame(201, $this->code('POST', '/staff/' . $vac['id'] . '/assignments', body: ['session_kind' => 'tp', 'element_ref' => 'EC-INFO', 'group_ref' => 'G1', 'level' => 'BTS1']));

        foreach ([
            fn () => $writer->assign('t1', 'e1', new AssignmentDraft((string) $vac['id'], 'atelier', 60), 'admin'),
            fn () => $writer->change('t1', 'e1', $id, ['inconnu' => 1]),
        ] as $call) {
            try {
                $call();
                self::fail('Validation attendue.');
            } catch (HttpException $e) {
                self::assertSame(422, $e->status);
            }
        }
        try {
            $writer->change('t2', 'e1', $id, ['planned_minutes' => 1]);
            self::fail('404 attendu.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testLesControlesDePayabiliteDeclaresParLApplicationSontAppliques(): void
    {
        $this->start([], true);
        $vac = $this->assistant();
        $assignment = $this->writer()->assign('t1', 'e1', new AssignmentDraft((string) $vac['id'], 'td', 120, 'EC1', 'G1', 'L2'), 'admin');

        // RG-ENS-1 : émargée et cahier de textes renseigné, mais pas contre-signée par le délégué
        $hour = $this->declaredHour((string) $vac['id'], 'prof', '2026-10-01', 120, 'td', ['assignment_id' => $assignment]);
        [$response, $body] = $this->api('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'boss');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('RG-ENS-1', (string) $body['detail']);
        $this->check->countersigned[(string) $hour['id']] = true;
        self::assertSame('validated', $this->api('POST', '/vacation-hours/' . $hour['id'] . '/validate', 'boss')[1]['data']['status']);
        self::assertContains('boss', $this->check->actors);

        // RG-ENS-4 : le volume de l'affectation (2 h) est atteint ; l'heure suivante attend l'accord écrit
        $extra = $this->declaredHour((string) $vac['id'], 'prof', '2026-10-02', 60, 'td', ['assignment_id' => $assignment]);
        $this->check->countersigned[(string) $extra['id']] = true;
        [$response, $body] = $this->api('POST', '/vacation-hours/' . $extra['id'] . '/validate', 'boss');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('RG-ENS-4', (string) $body['detail']);
        $this->check->overrunApproved[$assignment] = true;
        self::assertSame(200, $this->code('POST', '/vacation-hours/' . $extra['id'] . '/validate', 'boss'));

        // RG-ENS-5 : le relevé n'est approuvé (solde payé) qu'une fois les notes de l'EC saisies
        $statement = $this->statement((string) $vac['id']);
        self::assertSame(18000, $statement['gross_amount']);
        self::assertSame(200, $this->code('POST', '/vacation-statements/' . $statement['id'] . '/submit'));
        [$response, $body] = $this->api('POST', '/vacation-statements/' . $statement['id'] . '/approve', 'boss');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('notes de EC1 incomplètes', (string) $body['detail']);
        self::assertSame('pending_approval', $this->api('GET', '/vacation-statements/' . $statement['id'])[1]['data']['status']);
        $this->check->gradesComplete['EC1'] = true;
        self::assertSame('validated', $this->api('POST', '/vacation-statements/' . $statement['id'] . '/approve', 'boss')[1]['data']['status']);
    }
}
