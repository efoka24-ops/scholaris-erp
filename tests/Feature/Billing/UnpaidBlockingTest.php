<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Tests\Support\Billing\BillingTestCase;

/** Blocages d'impayé paramétrables : résultats, documents, réinscription ; jamais les cours ni les documents de droit. */
final class UnpaidBlockingTest extends BillingTestCase
{
    private function directory(): DebtorDirectory
    {
        return $this->container()->get(DebtorDirectory::class);
    }

    private function seed(): void
    {
        $this->seedIpesGrid();
        $this->invoiceFor('yann', 'g1', 'en1');
    }

    public function testAucunBlocageParDefaut(): void
    {
        $this->bootBilling();
        $this->seed();
        $this->clock->set('2027-03-01T09:00:00+01:00');

        self::assertSame(['yann'], $this->directory()->learnerIdsInArrears('t1', 'e1'));
        foreach (['results', 'documents', 'reinscription'] as $scope) {
            self::assertFalse($this->directory()->isBlocked('t1', 'e1', 'yann', $scope));
        }
    }

    public function testBlocagesParametresAvecSeuilEtDelai(): void
    {
        $this->bootBilling(['billing.blocks' => [
            'results' => ['enabled' => true, 'min_overdue_days' => 30, 'min_amount_xaf' => 100000],
            'documents' => ['enabled' => true, 'min_overdue_days' => 0, 'min_amount_xaf' => 1],
            'reinscription' => ['enabled' => false],
        ]]);
        $this->seed();

        // 20 octobre : inscription (08/10) et tranche 1 (15/10) en retard de 12 et 5 jours, 200 000 au total.
        $this->clock->set('2026-10-20T09:00:00+01:00');
        self::assertFalse($this->directory()->isBlocked('t1', 'e1', 'yann', 'results'), 'Moins de 30 jours de retard.');
        self::assertTrue($this->directory()->isBlocked('t1', 'e1', 'yann', 'documents'));
        self::assertFalse($this->directory()->isBlocked('t1', 'e1', 'yann', 'reinscription'), 'Blocage désactivé.');

        $this->clock->set('2026-11-20T09:00:00+01:00');
        self::assertTrue($this->directory()->isBlocked('t1', 'e1', 'yann', 'results'));
    }

    public function testJamaisLesCoursLaCompositionLesCertificatsNiLesPiecesFinancieres(): void
    {
        $this->bootBilling(['billing.blocks' => [
            'results' => ['enabled' => true], 'documents' => ['enabled' => true], 'reinscription' => ['enabled' => true],
            'courses' => ['enabled' => true], 'exams' => ['enabled' => true], 'receipt' => ['enabled' => true],
        ]]);
        $this->seed();
        $this->clock->set('2027-06-01T09:00:00+01:00');

        foreach (['courses', 'exams', 'composition', 'school_certificate', 'receipt', 'account_statement', 'payment_attestation'] as $scope) {
            self::assertFalse($this->directory()->isBlocked('t1', 'e1', 'yann', $scope), $scope . ' ne doit jamais être bloqué.');
        }
        self::assertTrue($this->directory()->isBlocked('t1', 'e1', 'yann', 'results'));
    }

    public function testUnApprenantAJourNEstPasBloque(): void
    {
        $this->bootBilling(['billing.blocks' => ['results' => ['enabled' => true]]]);
        $this->seed();
        $this->reference('g1');
        $this->openCash();
        $this->pay('cashier', ['amount_xaf' => 500000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        $this->clock->set('2027-06-01T09:00:00+01:00');

        self::assertFalse($this->directory()->isBlocked('t1', 'e1', 'yann', 'results'));
        self::assertSame(0, $this->directory()->balanceXaf('t1', 'e1', 'yann'));
        self::assertSame([], $this->directory()->learnerIdsInArrears('t1', 'e1'));
    }
}
