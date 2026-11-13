<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Scholaris\Modules\Socle\Billing\Application\YearBalanceCarryOver;
use Scholaris\Modules\Socle\Billing\Application\YearClosureCheck;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Organisation\Application\ClosureProviders;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Tests\Support\Billing\BillingTestCase;

/** Contrats de clôture d'année fournis par Billing : contrôle des impayés et report des soldes (idempotent). */
final class YearClosureTest extends BillingTestCase
{
    /** @var array<string, mixed> */
    private array $invoice = [];

    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
        $this->invoice = $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
        $this->clock->set('2026-10-10T10:00:00+01:00');
    }

    private function check(): YearClosureCheck
    {
        return $this->container()->get(YearClosureCheck::class);
    }

    private function carry(): YearBalanceCarryOver
    {
        return $this->container()->get(YearBalanceCarryOver::class);
    }

    public function testLesFournisseursSontDecouvertsParLOrganisation(): void
    {
        $providers = $this->container()->get(ClosureProviders::class);

        self::assertContains(YearClosureCheck::class, array_map('get_class', $providers->checks()));
        self::assertContains(YearBalanceCarryOver::class, array_map('get_class', $providers->carryOvers()));
        self::assertSame('billing.unpaid', $this->check()->key());
    }

    public function testLesImpayesSontReportesSansBloquerParDefaut(): void
    {
        $finding = $this->check()->evaluate('t1', 'e1', 'ay1');

        self::assertFalse($finding->blocks());
        self::assertSame('ok', $finding->toArray()['status']);
        self::assertStringContainsString('1 facture(s) impayée(s) pour 500000 XAF', (string) $finding->detail);
    }

    public function testUneCaisseOuverteOuUnPaiementEnAttenteBloque(): void
    {
        $session = $this->openCash();

        $finding = $this->check()->evaluate('t1', 'e1', 'ay1');
        self::assertTrue($finding->blocks());
        self::assertSame(1, $finding->blockingCount);
        self::assertStringContainsString('caisse(s) ouverte(s)', (string) $finding->detail);

        $this->api('POST', '/cash-sessions/' . $session . '/close', 'cashier', ['counted_xaf' => 50000]);
        self::assertFalse($this->check()->evaluate('t1', 'e1', 'ay1')->blocks());
        self::assertFalse($this->check()->evaluate('t1', 'e2', 'ay1')->blocks(), 'Un autre établissement n\'est pas concerné.');
    }

    public function testLesImpayesBloquentSiLeParametreLeDemande(): void
    {
        $this->settings->values['billing.closure.block_on_unpaid'] = true;

        $finding = $this->check()->evaluate('t1', 'e1', 'ay1');

        self::assertTrue($finding->blocks());
        self::assertSame(1, $finding->blockingCount);
        self::assertSame(0, $this->check()->evaluate('t1', 'e1', 'ay-sans-facture')->blockingCount);
    }

    public function testReportDesSoldesPuisRejeuSansEffet(): void
    {
        $session = $this->openCash();
        $this->pay('cashier', ['amount_xaf' => 60000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        $this->api('POST', '/cash-sessions/' . $session . '/close', 'cashier', ['counted_xaf' => 110000]);

        $result = $this->carry()->carryOver('t1', 'e1', 'ay1', 'ay2');

        self::assertSame(ClosureStepResult::DONE, $result->status);
        self::assertSame(['invoices_carried' => 1, 'amount_xaf' => 440000], $result->counts);
        $old = $this->rows("SELECT * FROM invoices WHERE academic_year_id = 'ay1'")[0];
        self::assertSame(['carried', 0, 60000], [$old['status'], (int) $old['balance_xaf'], (int) $old['paid_xaf']]);
        $new = $this->rows("SELECT * FROM invoices WHERE academic_year_id = 'ay2'");
        self::assertCount(1, $new);
        self::assertSame(['open', 440000, 'yann'], [$new[0]['status'], (int) $new[0]['balance_xaf'], $new[0]['learner_id']]);
        self::assertSame(440000, (int) $this->rows("SELECT SUM(amount_xaf - paid_xaf) AS due FROM installments WHERE learner_id = 'yann'")[0]['due'], 'Le solde n\'est pas compté deux fois.');
        self::assertSame(440000, $this->container()->get(DebtorDirectory::class)->balanceXaf('t1', 'e1', 'yann'));
        self::assertSame(1, (int) $this->rows("SELECT COUNT(*) AS n FROM audit_log WHERE action = 'invoice.balance_carried_over'")[0]['n']);

        $again = $this->carry()->carryOver('t1', 'e1', 'ay1', 'ay2');
        self::assertSame(['invoices_carried' => 0, 'amount_xaf' => 0], $again->counts);
        self::assertCount(1, $this->rows("SELECT id FROM invoices WHERE academic_year_id = 'ay2'"));
        self::assertCount(1, $this->rows('SELECT id FROM balance_carry_overs'));
    }

    public function testLeSoldeReporteSePayeCommeUneFactureOrdinaire(): void
    {
        $this->carry()->carryOver('t1', 'e1', 'ay1', 'ay2');
        $this->openCash();

        [$response] = $this->pay('cashier', ['amount_xaf' => 100000, 'family_reference' => 'FAM-0001'], '/cash/receipts');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(400000, $this->container()->get(DebtorDirectory::class)->balanceXaf('t1', 'e1', 'yann'));
        self::assertSame(400000, (int) $this->rows("SELECT balance_xaf AS b FROM invoices WHERE academic_year_id = 'ay2'")[0]['b'], 'La facture reportée est affectée.');
    }

    public function testLeReportNeToucheNiLesAutresEtablissementsNiLesAutresClients(): void
    {
        $this->carry()->carryOver('t1', 'e2', 'ay1', 'ay2');
        $this->carry()->carryOver('t2', 'x1', 'ay1', 'ay2');

        self::assertSame('open', $this->rows("SELECT status FROM invoices WHERE academic_year_id = 'ay1'")[0]['status']);
        self::assertCount(0, $this->rows('SELECT id FROM balance_carry_overs'));
    }
}
