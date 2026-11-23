<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use RuntimeException;
use Scholaris\Modules\Socle\Billing\Application\PaymentService;
use Scholaris\Modules\Socle\Billing\Application\RecordPayment;
use Scholaris\Modules\Socle\Billing\Domain\PaymentChannel;
use Tests\Support\Billing\BillingTestCase;

/**
 * Reçus numérotés sans trou par établissement et année. Le numéro est pris dans la transaction du paiement :
 * 100 encaissements enchaînés donnent 100 numéros consécutifs, et un échec en cours de route ne consomme aucun numéro.
 */
final class ReceiptNumberingTest extends BillingTestCase
{
    private PaymentService $service;

    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
        $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
        $this->openCash();
        $this->service = $this->container()->get(PaymentService::class);
    }

    private function cash(string $key, int $amount = 1000, string $establishment = 'e1'): ?string
    {
        return $this->service->record(new RecordPayment('t1', $establishment, 'cashier', PaymentChannel::Cash, $amount, $key, 'FAM-0001'))->receipt['number'] ?? null;
    }

    public function testCentEncaissementsDonnentCentNumerosConsecutifs(): void
    {
        $numbers = [];
        for ($i = 1; $i <= 100; ++$i) {
            $numbers[] = $this->cash('lot-' . $i);
        }

        self::assertSame('R-IPES-26-00001', $numbers[0]);
        self::assertSame('R-IPES-26-00100', $numbers[99]);
        self::assertCount(100, array_unique($numbers));
        $sequence = array_map(static fn (array $r): int => (int) $r['seq_no'], $this->rows('SELECT seq_no FROM receipts ORDER BY seq_no'));
        self::assertSame(range(1, 100), $sequence);
    }

    public function testUnEchecEnCoursDeTransactionNeConsommePasLeNumero(): void
    {
        self::assertSame('R-IPES-26-00001', $this->cash('avant'));

        $this->audit->failOn = 'payment.recorded';
        try {
            $this->cash('en-panne');
            self::fail('La panne simulée aurait dû interrompre le paiement.');
        } catch (RuntimeException) {
            // attendu
        }
        $this->audit->failOn = null;

        self::assertCount(1, $this->rows('SELECT id FROM payments'), 'Le paiement en échec est annulé avec sa transaction.');
        self::assertCount(1, $this->rows('SELECT id FROM receipts'));
        self::assertSame('R-IPES-26-00002', $this->cash('apres'), 'Le numéro 2 n\'a pas été consommé par l\'échec.');
        self::assertSame(2, (int) $this->rows('SELECT current_value FROM number_sequences WHERE kind = \'receipt\'')[0]['current_value']);
    }

    public function testLaNumerotationEstIndependanteParEtablissementEtParAnnee(): void
    {
        $this->seedIpesGrid('e2');
        $this->api('POST', '/cash-sessions', 'cashier', ['float_xaf' => 0], 't1', 'e2');
        $this->invoiceFor('nadia', 'g1', 'en2', 'e2');
        self::assertSame('R-IPES-26-00001', $this->cash('e1-a'));
        self::assertSame('R-IPES-26-00001', $this->cash('e2-a', 1000, 'e2'), 'Chaque établissement a sa propre suite.');

        $this->clock->set('2027-01-05T09:00:00+01:00');
        self::assertSame('R-IPES-27-00001', $this->cash('e1-b'), 'La suite repart à 1 chaque année.');
    }

    public function testUnPaiementEnAttenteNAPasDeNumero(): void
    {
        [$response, $json] = $this->pay('cashier', ['channel' => 'mtn_momo', 'amount_xaf' => 5000, 'family_reference' => 'FAM-0001', 'payer_phone' => '+237670000000']);

        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('pending', $json['data']['status']);
        self::assertNull($json['data']['receipt']);
        self::assertCount(0, $this->rows('SELECT id FROM receipts'));
    }
}
