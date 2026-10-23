<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Vacations;

use Scholaris\Modules\Apps\Higher\Vacations\Application\VacationPermissions;
use Tests\Support\Higher\Vacations\VacationsTestCase;

/** Visa de l'école, approbation de la direction, paiement par le comptable (NOT-14), DOC-25 et DOC-26, RG-ENS-5. */
final class VacationWorkflowTest extends VacationsTestCase
{
    protected function setUp(): void
    {
        $this->bootVacations();
        $this->tst11();
    }

    public function testCircuitCompletJusquAuPaiementEtNot14(): void
    {
        $id = (string) $this->prepare()['id'];

        self::assertSame(403, $this->call('POST', '/vacations/statements/' . $id . '/approve', self::DIRECTOR)[0]);
        self::assertSame(409, $this->call('POST', '/vacations/statements/' . $id . '/approve', self::DG)[0], 'pas de visa de l\'école');
        self::assertSame('school_validated', $this->call('POST', '/vacations/statements/' . $id . '/validate-school', self::DIRECTOR)[1]['status']);
        self::assertSame('approved', $this->call('POST', '/vacations/statements/' . $id . '/approve', self::DG)[1]['status']);
        self::assertSame(403, $this->call('POST', '/vacations/statements/' . $id . '/pay', self::DG, ['payment_method' => 'mobile_money', 'payment_reference' => 'OM-1'])[0]);

        [$status, $paid] = $this->call('POST', '/vacations/statements/' . $id . '/pay', self::ACCOUNTANT, ['payment_method' => 'mobile_money', 'payment_reference' => 'OM-123']);

        self::assertSame(200, $status);
        self::assertSame('paid', $paid['status']);
        self::assertSame('2026-12-05', $paid['paid_on']);
        $last = end($this->fakes->sent);
        self::assertIsArray($last);
        self::assertSame([self::PERSON, VacationPermissions::NOT14_PAID, '261 000'], [$last['person'], $last['template'], $last['variables']['amount']]);
        self::assertSame(409, $this->call('POST', '/vacations/statements/' . $id . '/reject', self::DG, ['reason' => 'trop tard'])[0]);
        self::assertSame(409, $this->call('POST', '/vacations/statements', self::CHEF, ['person_id' => self::PERSON, 'period_month' => self::MONTH])[0]);
    }

    public function testDocumentsEtatEtBulletin(): void
    {
        $id = (string) $this->prepare()['id'];
        self::assertSame(201, $this->call('POST', '/vacations/statements/' . $id . '/statement-document', self::CHEF)[0]);
        self::assertSame('DOC-25', $this->fakes->issued[0]['code']);
        self::assertSame('261 000', $this->fakes->issued[0]['data']['gross_amount']);
        self::assertSame('43,5', $this->fakes->issued[0]['data']['hetd_total']);
        self::assertSame(409, $this->call('POST', '/vacations/statements/' . $id . '/payslip', self::ACCOUNTANT)[0]);

        $this->call('POST', '/vacations/statements/' . $id . '/validate-school', self::DIRECTOR);
        $this->call('POST', '/vacations/statements/' . $id . '/approve', self::DG);
        $this->call('POST', '/vacations/statements/' . $id . '/pay', self::ACCOUNTANT, ['payment_method' => 'bank_transfer', 'payment_reference' => 'VIR-9']);
        self::assertSame(201, $this->call('POST', '/vacations/statements/' . $id . '/payslip', self::ACCOUNTANT)[0]);
        self::assertSame('DOC-26', $this->fakes->issued[1]['code']);
        self::assertSame('doc-2', $this->call('GET', '/vacations/statements/' . $id, self::ACCOUNTANT)[1]['payslip_document_id']);
    }

    public function testSoldeRetenuSiNotesIncompletesRgEns5(): void
    {
        $this->fakes->complete[self::ELEMENT] = false;
        $statement = $this->prepare();
        self::assertStringContainsString('RG-ENS-5', (string) $statement['hold_reasons'][0]);
        $id = (string) $statement['id'];
        $this->call('POST', '/vacations/statements/' . $id . '/validate-school', self::DIRECTOR);

        [$status, $json] = $this->call('POST', '/vacations/statements/' . $id . '/approve', self::DG);
        self::assertSame(409, $status);
        self::assertStringContainsString('RG-ENS-5', (string) $json['detail']);

        $this->fakes->complete[self::ELEMENT] = true;
        self::assertSame('approved', $this->call('POST', '/vacations/statements/' . $id . '/approve', self::DG)[1]['status']);
    }

    public function testRetenueDesactiveeParParametre(): void
    {
        $this->fakes->complete[self::ELEMENT] = false;
        $this->fakes->settings['higher.vacation.hold_until_grades'] = false;

        self::assertSame([], $this->prepare()['hold_reasons']);
    }

    public function testSansApprobationDeLaDirectionLeVisaApprouve(): void
    {
        $this->fakes->settings[VacationPermissions::DIRECTION_APPROVAL] = false;
        $id = (string) $this->prepare()['id'];

        self::assertSame('approved', $this->call('POST', '/vacations/statements/' . $id . '/validate-school', self::DIRECTOR)[1]['status']);
    }

    public function testRejetPuisRecalcul(): void
    {
        $id = (string) $this->prepare()['id'];
        self::assertSame(422, $this->call('POST', '/vacations/statements/' . $id . '/reject', self::DIRECTOR, [])[0]);
        self::assertSame('rejected', $this->call('POST', '/vacations/statements/' . $id . '/reject', self::DIRECTOR, ['reason' => 'Erreur de groupe'])[1]['status']);

        self::assertSame('draft', $this->prepare()['status']);
    }

    public function testPaiementValideLeCorps(): void
    {
        $id = (string) $this->prepare()['id'];

        [$status, $json] = $this->call('POST', '/vacations/statements/' . $id . '/pay', self::ACCOUNTANT, ['payment_method' => 'cash']);

        self::assertSame(422, $status);
        self::assertSame(['payment_method', 'payment_reference'], array_keys($json['errors']));
    }
}
