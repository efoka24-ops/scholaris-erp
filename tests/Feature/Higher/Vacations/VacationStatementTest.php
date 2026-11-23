<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Vacations;

use Scholaris\Modules\Apps\Higher\Vacations\Application\VacationPermissions;
use Tests\Support\Higher\Vacations\VacationsTestCase;

/** État mensuel des vacations : TST-11 exact et sa variante paramétrée, RG-ENS-1 (heures validées), RG-ENS-4, taux et retenues. */
final class VacationStatementTest extends VacationsTestCase
{
    protected function setUp(): void
    {
        $this->bootVacations();
    }

    public function testTst11AssistantL2NovembreDonne43Virgule5HetdEt261000Fcfa(): void
    {
        $this->tst11();

        $statement = $this->prepare();

        self::assertSame('draft', $statement['status']);
        self::assertSame([1080, 720, 360], [$statement['cm_minutes'], $statement['td_minutes'], $statement['tp_minutes']]);
        self::assertSame([27000, 12000, 4500], [$statement['cm_hetd_milli'], $statement['td_hetd_milli'], $statement['tp_hetd_milli']]);
        self::assertSame(43500, $statement['hetd_milli']);
        self::assertSame(43.5, $statement['hetd']);
        self::assertSame(6000, $statement['hourly_rate']);
        self::assertSame(261000, $statement['gross_amount']);
        self::assertSame(0, $statement['deductions_amount']);
        self::assertSame(261000, $statement['net_amount']);
        self::assertSame('higher.not14.hours_validated', $this->fakes->sent[0]['template']);
        self::assertSame('261 000', $this->fakes->sent[0]['variables']['amount']);
        self::assertSame('43,5', $this->fakes->sent[0]['variables']['details']);

        [$code, $detail] = $this->call('GET', '/vacations/statements/' . $statement['id'], self::CHEF);
        self::assertSame(200, $code);
        self::assertCount(15, $detail['lines']);
        self::assertTrue($detail['lines'][0]['payable']);
    }

    public function testVarianteHetdCmA2EstRecalculee(): void
    {
        $this->tst11();
        $first = $this->prepare();
        self::assertSame(261000, $first['gross_amount']);

        $this->fakes->settings['staff.hetd.cm'] = 2;
        $again = $this->prepare();

        self::assertSame($first['id'], $again['id']);
        self::assertSame(2000, $again['coef_cm']);
        self::assertSame(36000, $again['cm_hetd_milli']);
        self::assertSame(52500, $again['hetd_milli']);
        self::assertSame(315000, $again['gross_amount']);
    }

    public function testTauxParGradeEtNiveauPrimeSurLeTauxTousNiveaux(): void
    {
        $this->rate('assistant', 'L2', 7000);
        $this->tst11();

        self::assertSame(43500 * 7, $this->prepare()['gross_amount']);
    }

    public function testRetenuesParametrees(): void
    {
        $this->fakes->settings[VacationPermissions::DEDUCTIONS] = [['code' => 'irpp', 'label' => 'IRPP', 'percent' => 5.5]];
        $this->tst11();

        $statement = $this->prepare();

        self::assertSame(14355, $statement['deductions_amount']);
        self::assertSame(261000 - 14355, $statement['net_amount']);
        self::assertSame('IRPP', $statement['deductions'][0]['label']);
    }

    public function testRefusSiLesHeuresNeSontPasValidees(): void
    {
        $this->tst11('countersigned');

        [$status, $json] = $this->call('POST', '/vacations/statements', self::CHEF, ['person_id' => self::PERSON, 'period_month' => self::MONTH]);

        self::assertSame(409, $status);
        self::assertStringContainsString('RG-ENS-1', (string) $json['detail']);
    }

    public function testSeancesNonValideesExcluesEtComptees(): void
    {
        $this->tst11();
        $this->sessions($this->assignment('td', 600), 'td', 2, 120, 25, 'signed');

        $statement = $this->prepare();

        self::assertSame(261000, $statement['gross_amount']);
        self::assertSame(2, $statement['pending_sessions']);
    }

    public function testDepassementSansAccordEcritNonPaye(): void
    {
        $this->sessions($this->assignment('cm', 360), 'cm', 3, 180, 2);

        $statement = $this->prepare();

        self::assertSame(360, $statement['cm_minutes']);
        self::assertSame(180, $statement['excluded_minutes']);
        [, $detail] = $this->call('GET', '/vacations/statements/' . $statement['id'], self::CHEF);
        $refused = array_values(array_filter($detail['lines'], static fn (array $l): bool => !$l['payable']));
        self::assertCount(1, $refused);
        self::assertStringContainsString('RG-ENS-4', (string) $refused[0]['refusal']);
    }

    public function testSansTauxPourLeGradeRefus(): void
    {
        $this->fakes->staff[self::T . ':' . self::PERSON] = new \Scholaris\Modules\Socle\Staff\Contracts\StaffInfo(self::PERSON, self::T, 'vacataire', 'professeur', 'active', 'st-1');
        $this->tst11();

        [$status] = $this->call('POST', '/vacations/statements', self::CHEF, ['person_id' => self::PERSON, 'period_month' => self::MONTH]);

        self::assertSame(409, $status);
    }

    public function testValidationDuCorps(): void
    {
        [$status, $json] = $this->call('POST', '/vacations/statements', self::CHEF, ['period_month' => '2026-13', 'x' => 1]);

        self::assertSame(422, $status);
        self::assertSame(['person_id', 'period_month', 'x'], array_keys($json['errors']));
    }
}
