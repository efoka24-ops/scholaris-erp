<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Vacations;

use Tests\Fixtures\Higher\HigherOrgFixture;
use Tests\Support\Higher\Vacations\VacationsTestCase;

/** Isolation entre clients, périmètre école/département, « Mes vacations » : l'enseignant ne voit que ses états. */
final class VacationAccessTest extends VacationsTestCase
{
    protected function setUp(): void
    {
        $this->bootVacations();
        $this->tst11();
        $this->sessions($this->assignment('td', 1200, self::OTHER_PERSON), 'td', 2, 120, 3, 'validated', self::OTHER_PERSON);
    }

    public function testLEnseignantNeVoitQueSesVacations(): void
    {
        $mine = (string) $this->prepare()['id'];
        [, $other] = $this->call('POST', '/vacations/statements', self::CHEF, ['person_id' => self::OTHER_PERSON, 'period_month' => self::MONTH]);
        $otherId = (string) $other['data'][0]['id'];

        [$status, $list] = $this->call('GET', '/vacations/mine', self::TEACHER);
        self::assertSame(200, $status);
        self::assertSame([$mine], array_column($list['data'], 'id'));
        self::assertSame(261000, $list['data'][0]['gross_amount']);
        self::assertSame(200, $this->call('GET', '/vacations/statements/' . $mine, self::TEACHER)[0]);
        self::assertSame(404, $this->call('GET', '/vacations/statements/' . $otherId, self::TEACHER)[0]);
        self::assertSame(403, $this->call('GET', '/vacations/statements', self::TEACHER)[0]);
        self::assertSame(403, $this->call('POST', '/vacations/statements/' . $mine . '/validate-school', self::TEACHER)[0]);
        self::assertSame(403, $this->call('POST', '/vacations/statements', self::TEACHER, ['person_id' => self::PERSON, 'period_month' => self::MONTH])[0]);
    }

    public function testUnAutreClientNeVoitRien(): void
    {
        $id = (string) $this->prepare()['id'];
        $b = HigherOrgFixture::TENANT_B;
        $eb = HigherOrgFixture::ESTABLISHMENT_B;
        foreach (['higher.vacation.read', 'higher.vacation.pay', 'higher.hours.validate'] as $permission) {
            $this->assign($b, 'compta-b', $eb, $permission, 'establishment', null, 'comptable');
        }

        self::assertSame(404, $this->call('GET', '/vacations/statements/' . $id, 'compta-b', null, $b, $eb)[0]);
        self::assertSame([], $this->call('GET', '/vacations/statements', 'compta-b', null, $b, $eb)[1]['data']);
        self::assertSame(404, $this->call('POST', '/vacations/statements/' . $id . '/pay', 'compta-b', ['payment_method' => 'mobile_money', 'payment_reference' => 'X'], $b, $eb)[0]);
        self::assertSame(404, $this->call('POST', '/vacations/statements', 'compta-b', ['person_id' => self::PERSON, 'period_month' => self::MONTH], $b, $eb)[0]);
    }

    public function testUnChefDUnAutreDepartementNeVoitNiNEtablit(): void
    {
        $id = (string) $this->prepare()['id'];
        foreach (['higher.hours.validate', 'higher.vacation.read'] as $permission) {
            $this->assign(self::T, 'chef-dr', self::E, $permission, 'org_unit', 'EC-DR', 'higher_chef_dept');
        }

        self::assertSame(404, $this->call('GET', '/vacations/statements/' . $id, 'chef-dr')[0]);
        self::assertSame([], $this->call('GET', '/vacations/statements', 'chef-dr')[1]['data']);
        self::assertSame(404, $this->call('POST', '/vacations/statements', 'chef-dr', ['person_id' => self::PERSON, 'period_month' => self::MONTH])[0]);
        self::assertSame(2, count($this->call('GET', '/vacations/to-prepare?period_month=' . self::MONTH, self::CHEF)[1]['data']));
        self::assertSame([], $this->call('GET', '/vacations/to-prepare?period_month=' . self::MONTH, 'chef-dr')[1]['data']);
    }

    public function testUnDirecteurDUneAutreEcoleNeVisePas(): void
    {
        $id = (string) $this->prepare()['id'];
        $this->assign(self::T, 'dir-it', self::E, 'higher.vacation.validate_school', 'org_unit', 'IT', 'higher_dir_ecole');

        self::assertSame(403, $this->call('POST', '/vacations/statements/' . $id . '/validate-school', 'dir-it')[0]);
    }
}
