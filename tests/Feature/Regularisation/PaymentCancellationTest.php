<?php

declare(strict_types=1);

namespace Tests\Feature\Regularisation;

/**
 * Annulation d'un paiement (POST /platform/payments/{id}/cancel) après une déclaration de régularisation confirmée : le crédit
 * d'un paiement partiel est retiré (jamais de solde négatif), la déclaration liée passe à `cancelled` avec le motif, le client est
 * re-bloqué si le solde n'est plus couvert et le délai de grâce passé, le journal garde l'avant et l'après, le client est prévenu.
 *
 * Situation de départ (RegularisationCase::lateAndSuspended) : IPES, essai terminé le 6 janvier 2027, 60 000 FCFA par mois,
 * bloqué en lecture seule le 22 janvier (16 jours de retard, grâce de 15 jours). Numéros et références fictifs.
 */
final class PaymentCancellationTest extends RegularisationCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->lateAndSuspended();
    }

    /**
     * Déclare puis confirme un paiement ; rend [identifiant de la déclaration, identifiant du paiement].
     *
     * @return array{0: string, 1: string}
     */
    private function confirmed(string $kind, int $amount, string $reference, string $key): array
    {
        $body = ['app_key' => 'higher', 'kind' => $kind, 'channel' => 'orange_money', 'payer_phone' => '699 01 23 45', 'reference' => $reference, 'idempotency_key' => $key];
        if ($kind === 'partial') {
            $body['amount_xaf'] = $amount;
        }
        [$declared, $json] = $this->asBoss('POST', '/me/regularisation/declarations', $body);
        self::assertSame(201, $declared->getStatusCode(), (string) $declared->getBody());
        $id = (string) $json['data']['id'];
        [$ok] = $this->admin('POST', '/platform/regularisation/declarations/' . $id . '/confirm', ['received_amount_xaf' => $amount]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());

        return [$id, (string) $this->declaration($id)['payment_id']];
    }

    /** @return array<string, mixed> */
    private function declaration(string $id): array
    {
        $statement = $this->connections()->pdo()->prepare('SELECT * FROM platform_regularisation_requests WHERE id = ?');
        $statement->execute([$id]);

        return (array) $statement->fetch();
    }

    /** @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>} */
    private function cancel(string $paymentId, string $reason = 'Transfert non reçu sur le relevé'): array
    {
        return $this->admin('POST', '/platform/payments/' . $paymentId . '/cancel', ['reason' => $reason]);
    }

    /** @return list<array<string, mixed>> */
    private function cancellationAudits(): array
    {
        $rows = $this->connections()->pdo()->query("SELECT after_json FROM audit_log WHERE action = 'platform.payment_cancelled' AND tenant_id = 'platform'")->fetchAll();

        return array_map(static fn (array $r): array => (array) json_decode((string) $r['after_json'], true), $rows);
    }

    public function testPaiementPartielPuisAnnulationRetireLeCredit(): void
    {
        [$declarationId, $paymentId] = $this->confirmed('partial', 20000, 'OM-2027-PA-100001', 'cancel-partial-1');
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame(20000, (int) $row['regularisation_credit_xaf']);
        self::assertSame('2027-01-16', $row['paid_until']);

        [$response, $json] = $this->cancel($paymentId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('cancelled', $json['data']['status']);

        $row = $this->entitlement($this->ipes['tenant']);
        self::assertNull($row['regularisation_credit_xaf'], 'Le crédit du paiement annulé est retiré.');
        self::assertNull($row['regularisation_credit_anchor']);
        self::assertNull($row['paid_until'], 'La prolongation au prorata est retirée.');
        self::assertSame('suspended', $row['state'], 'Le client reste à son niveau.');
        self::assertSame('read_only', $row['regularisation']);
        self::assertFalse($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));

        $view = $this->asBoss('GET', '/me/regularisation')[1]['data'];
        self::assertSame(60000, $view['items'][0]['balance_xaf'], 'Solde de nouveau entier, jamais négatif.');
        self::assertSame(0, $view['items'][0]['credit_xaf']);

        $declaration = $this->declaration($declarationId);
        self::assertSame('cancelled', $declaration['state']);
        self::assertSame('Paiement annulé : Transfert non reçu sur le relevé', $declaration['decision_reason']);
        self::assertSame(1, $this->admin('GET', '/platform/regularisation/declarations?filter[state]=cancelled')[1]['meta']['counts']['cancelled']);

        $sent = $this->notifier->of('platform_payment_cancelled');
        self::assertCount(1, $sent);
        self::assertSame('compta@ipes.test', $sent[0]['email']);
        self::assertSame(20000, $sent[0]['variables']['amount']);
        self::assertStringContainsString('Solde restant : 60000 FCFA', (string) $sent[0]['variables']['details']);

        $audit = $this->cancellationAudits();
        self::assertCount(1, $audit);
        self::assertTrue($audit[0]['partial']);
        self::assertSame(20000, $audit[0]['before']['credit_xaf']);
        self::assertSame(0, $audit[0]['after']['credit_xaf']);
        self::assertSame(['id' => $declarationId, 'before' => 'verified', 'after' => 'cancelled'], $audit[0]['request_state']);

        // Une nouvelle déclaration est possible après l'annulation.
        $this->confirmed('partial', 10000, 'OM-2027-PA-100002', 'cancel-partial-2');
        self::assertSame(10000, (int) $this->entitlement($this->ipes['tenant'])['regularisation_credit_xaf']);
    }

    public function testModeCreditSeulPartielPuisAnnulation(): void
    {
        self::assertSame(200, $this->admin('PATCH', '/platform/settings', ['regularisation_partial_mode' => 1])[0]->getStatusCode());
        [, $paymentId] = $this->confirmed('partial', 20000, 'OM-2027-PA-200001', 'cancel-credit-1');
        self::assertNull($this->entitlement($this->ipes['tenant'])['paid_until']);

        self::assertSame(200, $this->cancel($paymentId)[0]->getStatusCode(), 'Un paiement partiel sans période couverte est annulable.');
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertNull($row['regularisation_credit_xaf']);
        self::assertSame('suspended', $row['state']);
    }

    public function testPartielPuisTotalPuisAnnulationDuTotalRebloqueEtRestitueLeCreditPartiel(): void
    {
        [$partialDeclaration, $partialPayment] = $this->confirmed('partial', 20000, 'OM-2027-PA-300001', 'cancel-mix-1');
        [$totalDeclaration, $totalPayment] = $this->confirmed('total', 40000, 'OM-2027-PA-300002', 'cancel-mix-2');
        self::assertSame('active', $this->entitlement($this->ipes['tenant'])['state']);
        self::assertTrue($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));

        self::assertSame(409, $this->cancel($partialPayment)[0]->getStatusCode(), 'Le plus récent d\'abord.');

        [$response] = $this->cancel($totalPayment, 'Référence en double');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame('suspended', $row['state'], 'Solde de nouveau non couvert et grâce dépassée : re-blocage.');
        self::assertSame('read_only', $row['regularisation'], 'Au niveau du client (réglage de la plateforme par défaut).');
        self::assertSame('auto', $row['regularisation_origin']);
        self::assertSame(20000, (int) $row['regularisation_credit_xaf'], 'Le paiement partiel, toujours valable, reste au crédit.');
        self::assertSame('2027-01-06', $row['regularisation_credit_anchor']);
        self::assertSame('2027-01-16', $row['paid_until']);
        self::assertFalse($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));
        self::assertSame(423, $this->asBoss('POST', '/messages', [])[0]->getStatusCode(), 'Blocage central rétabli.');
        self::assertSame(40000, $this->asBoss('GET', '/me/regularisation')[1]['data']['items'][0]['balance_xaf']);

        self::assertSame('cancelled', $this->declaration($totalDeclaration)['state']);
        self::assertSame('verified', $this->declaration($partialDeclaration)['state']);
        $details = (string) $this->notifier->of('platform_payment_cancelled')[0]['variables']['details'];
        self::assertStringContainsString('Solde restant : 40000 FCFA', $details);
        self::assertStringContainsString('lecture seule', $details);
        $audit = $this->cancellationAudits()[0];
        self::assertSame('active', $audit['before']['state']);
        self::assertSame('suspended', $audit['after']['state']);
        self::assertSame('read_only', $audit['relocked']);

        // Puis le partiel, devenu le plus récent.
        self::assertSame(200, $this->cancel($partialPayment)[0]->getStatusCode());
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertNull($row['regularisation_credit_xaf']);
        self::assertNull($row['paid_until']);
        self::assertSame('suspended', $row['state']);
    }

    public function testTotalPuisAnnulationRebloqueAuNiveauBloqueDuClient(): void
    {
        $statement = $this->connections()->pdo()->prepare("UPDATE tenant_applications SET lock_level = 'locked' WHERE tenant_id = ?");
        $statement->execute([$this->ipes['tenant']]);
        [, $paymentId] = $this->confirmed('total', 60000, 'OM-2027-PA-400001', 'cancel-total-1');
        self::assertSame('active', $this->entitlement($this->ipes['tenant'])['state']);

        self::assertSame(200, $this->cancel($paymentId)[0]->getStatusCode());
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame('suspended', $row['state']);
        self::assertSame('locked', $row['regularisation']);
        self::assertNull($row['paid_until']);
        self::assertNull($row['regularisation_credit_xaf']);
        self::assertSame('2027-01-07', $row['past_due_since']);
        self::assertSame(423, $this->asBoss('GET', '/people')[0]->getStatusCode(), 'Niveau bloqué : même la lecture est refusée.');
        self::assertStringContainsString('bloqué', (string) $this->notifier->of('platform_payment_cancelled')[0]['variables']['details']);
    }

    public function testDoubleAnnulationIdempotente(): void
    {
        [, $paymentId] = $this->confirmed('partial', 20000, 'OM-2027-PA-500001', 'cancel-twice-1');
        [$first, $one] = $this->cancel($paymentId);
        [$second, $two] = $this->cancel($paymentId, 'Autre motif');

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame($one['data'], $two['data'], 'Même réponse, motif d\'origine conservé.');
        self::assertCount(1, $this->cancellationAudits());
        self::assertCount(1, $this->notifier->of('platform_payment_cancelled'));
        self::assertNull($this->entitlement($this->ipes['tenant'])['regularisation_credit_xaf']);
    }

    public function testIsolationEtDroits(): void
    {
        $otherBefore = $this->entitlement($this->other['tenant']);
        [, $paymentId] = $this->confirmed('partial', 20000, 'OM-2027-PA-600001', 'cancel-iso-1');

        self::assertSame(403, $this->asBoss('POST', '/platform/payments/' . $paymentId . '/cancel', ['reason' => 'Je ne paie plus'])[0]->getStatusCode(), 'Un client n\'annule jamais un paiement.');
        self::assertSame(403, $this->asBoss('POST', '/platform/payments/' . $paymentId . '/cancel', ['reason' => 'x'], $this->other['tenant'])[0]->getStatusCode());
        self::assertSame(404, $this->cancel('01HZZZZZZZZZZZZZZZZZZZZZZZ')[0]->getStatusCode());
        self::assertSame(20000, (int) $this->entitlement($this->ipes['tenant'])['regularisation_credit_xaf'], 'Rien n\'a changé.');

        self::assertSame(200, $this->cancel($paymentId)[0]->getStatusCode());
        self::assertSame($otherBefore, $this->entitlement($this->other['tenant']), 'L\'autre client n\'est pas touché.');
        self::assertSame([], $this->asBoss('GET', '/me/regularisation', null, $this->other['tenant'])[1]['data']['requests']);
        foreach ($this->notifier->of('platform_payment_cancelled') as $message) {
            self::assertSame('compta@ipes.test', $message['email']);
        }
    }
}
