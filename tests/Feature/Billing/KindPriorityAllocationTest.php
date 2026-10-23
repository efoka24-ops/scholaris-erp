<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Tests\Support\Billing\BillingTestCase;

/** Paiement familial avec la règle « kind_priority » (SS7) : ordre des enfants paramétré. Données fictives. */
final class KindPriorityAllocationTest extends BillingTestCase
{
    /** @param array<string, mixed> $settings */
    private function bootFamily(array $settings): void
    {
        $births = new class () implements PersonDemographics {
            public function ofPersons(string $tenantId, array $personIds): array
            {
                $known = ['kid-young' => '2014-03-02', 'kid-old' => '2010-06-11'];
                $rows = [];
                foreach ($personIds as $id) {
                    $rows[$id] = ['gender' => null, 'birth_date' => $known[$id] ?? null];
                }

                return $rows;
            }
        };
        $this->bootBilling($settings + ['billing.sibling_discount' => ['mode' => 'fixed', 'value' => 0]], [PersonDemographics::class => $births]);
        $this->seedIpesGrid('e1');
        // Le cadet est facturé en premier, l'aîné ensuite.
        $this->invoiceFor('kid-young', 'g1', 'en-y', 'e1');
        $this->invoiceFor('kid-old', 'g1', 'en-o', 'e1');
        $this->reference('g1');
        $this->openCash();
        $this->clock->set('2026-09-25T09:00:00+01:00');
    }

    /** @return array<string, int> montant affecté par apprenant et nature */
    private function payFamily(int $amount): array
    {
        [$response, $json] = $this->pay('cashier', ['amount_xaf' => $amount, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        $out = [];
        foreach ($this->rows('SELECT a.learner_id, i.kind, a.amount_xaf, a.rule_version FROM allocations a JOIN installments i ON i.id = a.installment_id') as $row) {
            $key = $row['learner_id'] . ':' . $row['kind'];
            $out[$key] = ($out[$key] ?? 0) + (int) $row['amount_xaf'];
            self::assertSame('kind_priority@1', $row['rule_version']);
        }

        return $out;
    }

    public function testAineDAbordQuandLeParametreLeDemande(): void
    {
        $this->bootFamily(['billing.allocation_rule' => 'kind_priority', 'billing.allocation_sibling_order' => 'eldest_first']);

        $byKind = $this->payFamily(120000);

        self::assertSame(['kid-old:registration' => 50000, 'kid-old:tuition' => 20000, 'kid-young:registration' => 50000], self::sorted($byKind));
    }

    public function testParDefautLePremierInscritDAbord(): void
    {
        $this->bootFamily(['billing.allocation_rule' => 'kind_priority']);

        $byKind = $this->payFamily(120000);

        self::assertSame(['kid-old:registration' => 50000, 'kid-young:registration' => 50000, 'kid-young:tuition' => 20000], self::sorted($byKind));
    }

    /**
     * @param array<string, int> $values
     * @return array<string, int>
     */
    private static function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }
}
