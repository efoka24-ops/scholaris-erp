<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Billing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Billing\Application\AccountService;
use Scholaris\Modules\Apps\Higher\Billing\Domain\AccountLedger;
use Scholaris\Modules\Apps\Higher\Billing\Domain\HigherFees;
use Scholaris\Modules\Apps\Higher\Billing\Domain\PaymentTerms;
use Scholaris\Modules\Apps\Higher\Billing\Domain\ScholarshipRules;

/** Calculs purs du module FIN : échéancier, lignes de facture, état des tranches, relances J-7/J+1/J+15, plafond. */
final class BillingDomainTest extends TestCase
{
    public function testEcheancierParDefautEtDecoupageEntier(): void
    {
        $terms = PaymentTerms::defaultFor(2026);
        self::assertSame(['2026-10-15', '2027-01-15', '2027-04-15'], array_column($terms->toArray(), 'due_on'));
        self::assertSame([150000, 150000, 150000], $terms->split(450000));
        self::assertSame([33333, 33333, 33334], $terms->split(100000), 'Reliquat sur la dernière tranche');
        self::assertSame([100, 200], PaymentTerms::fromArray([['due_on' => '2026-11-30', 'weight' => 1], ['due_on' => '2027-03-31', 'weight' => 2]])->split(300));
    }

    public function testEcheancierInvalide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PaymentTerms::fromArray([['due_on' => '2027-02-30', 'weight' => 1]]);
    }

    public function testLignesDeFactureDepuisLaGrille(): void
    {
        $lines = AccountService::chargeLines([
            ['fee_code' => HigherFees::INSCRIPTION, 'amount_xaf' => 50000, 'due_on' => null],
            ['fee_code' => HigherFees::PENSION, 'amount_xaf' => 450000, 'due_on' => null],
            ['fee_code' => HigherFees::ASSURANCE, 'amount_xaf' => 0, 'due_on' => null],
        ], PaymentTerms::defaultFor(2026));
        self::assertSame(['hi.inscription', 'hi.pension', 'hi.pension', 'hi.pension'], array_map(static fn ($l): ?string => $l->feeCode, $lines));
        self::assertSame([50000, 150000, 150000, 150000], array_map(static fn ($l): ?int => $l->amountXaf, $lines));
        self::assertSame('Pension · tranche 2/3', $lines[2]->label);
        self::assertSame('2026-10-15', $lines[0]->dueOn, 'Inscription à la date de la première tranche');
    }

    public function testEtatDesTranchesEtRelances(): void
    {
        $installments = [
            ['id' => 'a', 'label' => 'Inscription', 'due_on' => '2026-10-15', 'amount_xaf' => 50000, 'paid_xaf' => 50000],
            ['id' => 'b', 'label' => 'T1', 'due_on' => '2026-10-15', 'amount_xaf' => 120000, 'paid_xaf' => 120000],
            ['id' => 'c', 'label' => 'T2', 'due_on' => '2027-01-15', 'amount_xaf' => 120000, 'paid_xaf' => 100000],
            ['id' => 'd', 'label' => 'T3', 'due_on' => '2027-04-15', 'amount_xaf' => 120000, 'paid_xaf' => 0],
        ];
        $onDueDate = new AccountLedger($installments, '2027-01-15');
        self::assertTrue($onDueDate->isUpToDate(), 'Le retard commence le lendemain');
        $late = new AccountLedger($installments, '2027-01-16');
        self::assertSame(['paid', 'paid', 'overdue', 'pending'], array_column($late->lines(), 'status'));
        self::assertSame(20000, $late->overdueXaf());
        self::assertSame(140000, $late->remainingXaf());
        self::assertSame(['NOT-09', 1], [$late->remindersOn([-7, 1, 15])[0]['notification'], $late->remindersOn([-7, 1, 15])[0]['offset']]);
        self::assertSame('NOT-08', (new AccountLedger($installments, '2027-04-08'))->remindersOn([-7, 1, 15])[0]['notification']);
        self::assertSame([], (new AccountLedger($installments, '2026-10-16'))->remindersOn([-7, 1, 15]), 'Tranche soldée : pas de relance');
        self::assertSame('c', $late->nextDue()['id'] ?? null);
    }

    public function testPlafondDesBourses(): void
    {
        self::assertSame(90000, ScholarshipRules::amount('percent', 20, 450000));
        self::assertSame(5000, ScholarshipRules::amount('fixed', 5000, 450000));
        self::assertFalse(ScholarshipRules::exceedsCap(225000, 450000, 50));
        self::assertTrue(ScholarshipRules::exceedsCap(225001, 450000, 50));
        self::assertSame('merit', ScholarshipRules::SOCLE_KIND['partnership']);
    }
}
