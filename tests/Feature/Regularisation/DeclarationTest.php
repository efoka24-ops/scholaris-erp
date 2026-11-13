<?php

declare(strict_types=1);

namespace Tests\Feature\Regularisation;

use Scholaris\Modules\Socle\Platform\Application\RegularisationRequests;

/**
 * Circuit de régularisation par déclaration de paiement : le responsable déclare, la plateforme vérifie (webhook ou super admin),
 * seul un paiement vérifié débloque. Numéros fictifs +2376990xxxxx.
 */
final class DeclarationTest extends RegularisationCase
{
    private const PHONE = '+237699012345';
    private const REF = 'OM-2027-TX-778899';

    protected function setUp(): void
    {
        parent::setUp();
        $this->lateAndSuspended();
        [$contact] = $this->admin('PATCH', '/platform/settings/contact', [
            'name' => 'TRU GROUP', 'email' => 'paiements@trugroup.test', 'phone' => '6 99 00 00 01',
            'payments' => [['channel' => 'orange_money', 'phone' => '699 00 00 02', 'holder' => 'TRU GROUP SARL'], ['channel' => 'mtn_momo', 'phone' => '+237 670 00 00 03', 'holder' => 'TRU GROUP SARL']],
        ]);
        self::assertSame(200, $contact->getStatusCode(), (string) $contact->getBody());
    }

    /** @return array<string, mixed> */
    private function body(array $changes = []): array
    {
        return array_replace([
            'app_key' => 'higher', 'kind' => 'total', 'channel' => 'orange_money', 'payer_phone' => '699 01 23 45',
            'reference' => self::REF, 'idempotency_key' => 'decl-ipes-0001',
        ], $changes);
    }

    /** @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>} */
    private function declare(array $changes = [], bool $other = false): array
    {
        return $this->asBoss('POST', '/me/regularisation/declarations', $this->body($changes), $other ? $this->other['tenant'] : null);
    }

    /** @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>} */
    private function webhookMomo(string $eventId, int $amount, string $reference, ?string $phone = null): array
    {
        $body = json_encode(array_filter(['event_id' => $eventId, 'type' => 'payment.succeeded', 'amount' => $amount, 'provider_reference' => $reference, 'phone' => $phone]), JSON_THROW_ON_ERROR);

        return $this->raw('POST', '/platform/payments/webhook', ['Content-Type' => 'application/json', 'X-Signature' => hash_hmac('sha256', $body, self::SECRET)], $body);
    }

    private function confirm(string $id, array $body): array
    {
        return $this->admin('POST', '/platform/regularisation/declarations/' . $id . '/confirm', $body);
    }

    public function testLaDeclarationNeDebloquePasEtEstIdempotente(): void
    {
        [$created, $json] = $this->declare();
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame('declared', $json['data']['state']);
        self::assertSame('total', $json['data']['kind']);
        self::assertSame(60000, $json['data']['declared_amount_xaf']);
        self::assertSame('+2376*****345', $json['data']['payer_phone_masked']);
        self::assertStringNotContainsString('699012345', (string) $created->getBody(), 'Le numéro payeur n\'est jamais renvoyé en clair.');

        self::assertFalse($this->access()->allowsWrites($this->ipes['tenant'], 'higher'), 'Déclarer ne débloque jamais.');
        self::assertSame('suspended', $this->entitlement($this->ipes['tenant'])['state']);

        [$again, $same] = $this->declare();
        self::assertSame(200, $again->getStatusCode(), 'Même clé d\'idempotence : même demande.');
        self::assertSame($json['data']['id'], $same['data']['id']);
        self::assertSame(409, $this->declare(['idempotency_key' => 'decl-ipes-0002'])[0]->getStatusCode(), 'Une seule demande ouverte à la fois.');
        self::assertSame(1, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM platform_regularisation_requests')->fetchColumn());

        $operator = $this->notifier->of('platform_regularisation_declared');
        self::assertCount(1, $operator, 'Le super admin est prévenu une fois.');
        self::assertSame('paiements@trugroup.test', $operator[0]['email']);
        self::assertSame('+237699000001', $operator[0]['phone']);
    }

    public function testDroitsEtValidationDuFormulaire(): void
    {
        self::assertSame(403, $this->api('POST', '/me/regularisation/declarations', 'teacher', $this->ipes['tenant'], $this->body(), $this->ipes['establishment'])[0]->getStatusCode(), 'Seul le responsable déclare.');
        self::assertSame(403, $this->admin('POST', '/me/regularisation/declarations', $this->body())[0]->getStatusCode(), 'Un compte de plateforme n\'est pas un client.');
        foreach ([
            ['payer_phone' => '12345'], ['payer_phone' => '+33 6 12 34 56 78'], ['channel' => 'cash'], ['kind' => 'autre'], ['reference' => 'ab'],
            ['amount_xaf' => 60000], ['kind' => 'partial'], ['kind' => 'partial', 'amount_xaf' => 500], ['kind' => 'partial', 'amount_xaf' => 60000], ['app_key' => 'Higher!'],
        ] as $bad) {
            [$response, $json] = $this->declare($bad);
            self::assertSame(422, $response->getStatusCode(), json_encode($bad) . ' ' . $response->getBody());
            self::assertNotEmpty($json['errors']);
        }
        self::assertSame(422, $this->asBoss('POST', '/me/regularisation/declarations', $this->body() + ['inconnu' => 1])[0]->getStatusCode());
        $fresh = $this->client('Collège Neuf');
        $this->api('POST', '/applications/higher/open', 'boss', $fresh['tenant'], null, $fresh['establishment']);
        self::assertSame(409, $this->api('POST', '/me/regularisation/declarations', 'boss', $fresh['tenant'], $this->body(), $fresh['establishment'])[0]->getStatusCode(), 'Le client à jour (essai) n\'a aucun solde à régulariser.');
        self::assertSame(404, $this->declare(['app_key' => 'primary'])[0]->getStatusCode());
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM platform_regularisation_requests')->fetchColumn());
    }

    public function testConfirmationTotaleParLeSuperAdminDebloqueEtNotifie(): void
    {
        $id = $this->declare()[1]['data']['id'];
        $this->secondFactor->revoke(self::ROOT);
        self::assertSame(403, $this->confirm($id, ['received_amount_xaf' => 60000])[0]->getStatusCode(), '2FA récente requise.');
        $this->secondFactor->allow(self::ROOT);
        self::assertSame(403, $this->api('POST', '/platform/regularisation/declarations/' . $id . '/confirm', 'boss', $this->ipes['tenant'], ['received_amount_xaf' => 60000], $this->ipes['establishment'])[0]->getStatusCode(), 'Le client ne confirme jamais son propre paiement.');
        self::assertFalse($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));
        self::assertSame(422, $this->confirm($id, [])[0]->getStatusCode());

        [$ok, $json] = $this->confirm($id, ['received_amount_xaf' => 60000, 'note' => 'Relevé Orange du 22/01']);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame('verified', $json['data']['state']);
        self::assertTrue($json['data']['unlocked']);
        self::assertSame('manual', $json['data']['verified_via']);

        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame('active', $row['state']);
        self::assertNull($row['regularisation']);
        self::assertSame('2027-02-21', $row['paid_until'], 'Un mois à partir du jour du paiement.');
        self::assertTrue($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));
        self::assertNotSame(423, $this->asBoss('POST', '/messages', [])[0]->getStatusCode(), 'Déblocage immédiat du socle.');
        self::assertSame('ok', $this->asBoss('GET', '/me/regularisation')[1]['data']['mode']);

        $payments = $this->connections()->pdo()->query("SELECT channel, amount_xaf, receipt_number, status FROM platform_payments WHERE tenant_id = '" . $this->ipes['tenant'] . "'")->fetchAll();
        self::assertCount(1, $payments);
        self::assertSame('orange_money', $payments[0]['channel']);
        self::assertSame('succeeded', $payments[0]['status']);
        self::assertNotEmpty($payments[0]['receipt_number'], 'Reçu généré.');

        $confirmed = $this->notifier->of('platform_regularisation_confirmed');
        self::assertCount(1, $confirmed);
        self::assertSame('compta@ipes.test', $confirmed[0]['email']);
        self::assertSame(60000, $confirmed[0]['variables']['amount']);
        self::assertCount(0, $this->notifier->of('platform_payment_receipt'), 'Un seul message au client, pas trois.');
        self::assertSame(409, $this->confirm($id, ['received_amount_xaf' => 60000])[0]->getStatusCode(), 'Déjà traitée.');
        self::assertSame(409, $this->admin('POST', '/platform/regularisation/declarations/' . $id . '/reject', ['reason' => 'trop tard'])[0]->getStatusCode());

        $audit = $this->connections()->pdo()->query("SELECT after_json FROM audit_log WHERE action = 'platform.regularisation_confirmed' AND tenant_id = 'platform'")->fetchColumn();
        self::assertIsString($audit);
        foreach (['"before"', '"after"', 'declared_by', 'received_amount_xaf', 'request_state'] as $needle) {
            self::assertStringContainsString($needle, $audit);
        }
    }

    public function testPaiementPartielProlongeProportionnellementEtGardeLeNiveauJusquAuSolde(): void
    {
        $this->clock->day('2027-01-22');
        [$low] = $this->declare(['kind' => 'partial', 'amount_xaf' => 20000]);
        self::assertSame(201, $low->getStatusCode(), (string) $low->getBody());
        $first = $this->asBoss('GET', '/me/regularisation')[1]['data'];
        self::assertSame(1000, $first['partial_min_xaf']);
        self::assertSame(60000, $first['items'][0]['balance_xaf']);
        $id = $first['requests'][0]['id'];

        [$ok, $json] = $this->confirm($id, ['received_amount_xaf' => 20000]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertFalse($json['data']['unlocked']);
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame('suspended', $row['state'], 'Le niveau reste tant que le solde n\'est pas couvert.');
        self::assertSame('read_only', $row['regularisation']);
        self::assertSame(20000, (int) $row['regularisation_credit_xaf']);
        self::assertSame('2027-01-16', $row['paid_until'], 'Un tiers du solde : 10 jours sur 31, depuis l\'échéance du cycle (6 janvier).');
        self::assertFalse($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));
        self::assertSame(423, $this->asBoss('POST', '/messages', [])[0]->getStatusCode());
        $view = $this->asBoss('GET', '/me/regularisation')[1]['data'];
        self::assertSame(40000, $view['items'][0]['balance_xaf']);
        self::assertSame(20000, $view['items'][0]['credit_xaf']);
        self::assertCount(1, $this->notifier->of('platform_regularisation_partial'));
        self::assertStringContainsString('40000', (string) $this->notifier->of('platform_regularisation_partial')[0]['variables']['details']);

        // Solde déclaré en total (40 000) puis confirmé : débloque, depuis l'échéance du cycle et non depuis l'avance.
        [$rest, $restJson] = $this->declare(['idempotency_key' => 'decl-ipes-0002', 'reference' => 'OM-2027-TX-112233']);
        self::assertSame(201, $rest->getStatusCode(), (string) $rest->getBody());
        self::assertSame(40000, $restJson['data']['declared_amount_xaf']);
        [$done, $final] = $this->confirm($restJson['data']['id'], ['received_amount_xaf' => 40000]);
        self::assertSame(200, $done->getStatusCode(), (string) $done->getBody());
        self::assertTrue($final['data']['unlocked']);
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame('active', $row['state']);
        self::assertNull($row['regularisation_credit_xaf']);
        self::assertSame('2027-02-21', $row['paid_until']);
        self::assertTrue($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));
        self::assertCount(1, $this->notifier->of('platform_regularisation_confirmed'));
        self::assertSame(2, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM platform_payments WHERE status = 'succeeded' AND tenant_id = '" . $this->ipes['tenant'] . "'")->fetchColumn(), 'Un reçu par versement.');
    }

    public function testReglageCreditSeulementNeProlongePasLaPeriode(): void
    {
        self::assertSame(200, $this->admin('PATCH', '/platform/settings', ['regularisation_partial_mode' => 1])[0]->getStatusCode());
        $id = $this->declare(['kind' => 'partial', 'amount_xaf' => 20000])[1]['data']['id'];
        self::assertSame(200, $this->confirm($id, ['received_amount_xaf' => 20000])[0]->getStatusCode());
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertNull($row['paid_until'], 'Mode crédit seul : aucune prolongation.');
        self::assertSame(20000, (int) $row['regularisation_credit_xaf']);
        self::assertSame('suspended', $row['state']);
    }

    public function testPromesseDePaiementAccordeeAvecUnPaiementPartiel(): void
    {
        $id = $this->declare(['kind' => 'partial', 'amount_xaf' => 30000])[1]['data']['id'];
        self::assertSame(422, $this->confirm($id, ['received_amount_xaf' => 30000, 'promise_until' => '2027-01-01'])[0]->getStatusCode(), 'Date passée.');
        self::assertSame('declared', $this->asBoss('GET', '/me/regularisation')[1]['data']['requests'][0]['state'], 'Rien n\'a été appliqué (transaction annulée).');
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM platform_payments WHERE tenant_id = \'' . $this->ipes['tenant'] . '\'')->fetchColumn());

        [$ok] = $this->confirm($id, ['received_amount_xaf' => 30000, 'promise_until' => '2027-02-10']);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame('2027-02-10', $row['promise_until']);
        self::assertTrue($this->access()->allowsWrites($this->ipes['tenant'], 'higher'), 'Promesse du super admin : accès rétabli jusqu\'à la date.');
        self::assertSame(30000, (int) $row['regularisation_credit_xaf'], 'Le solde reste à couvrir.');
    }

    public function testRejetNotifieLeMotifEtPermetUneNouvelleDeclaration(): void
    {
        $id = $this->declare()[1]['data']['id'];
        self::assertSame(422, $this->admin('POST', '/platform/regularisation/declarations/' . $id . '/reject', [])[0]->getStatusCode(), 'Motif obligatoire.');
        [$ok, $json] = $this->admin('POST', '/platform/regularisation/declarations/' . $id . '/reject', ['reason' => 'Aucun paiement de 60 000 FCFA sur le relevé']);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame('rejected', $json['data']['state']);
        $sent = $this->notifier->of('platform_regularisation_rejected');
        self::assertCount(1, $sent);
        self::assertSame('Aucun paiement de 60 000 FCFA sur le relevé', $sent[0]['variables']['details']);
        self::assertSame(self::REF, $sent[0]['variables']['reference']);
        self::assertFalse($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));
        self::assertSame('rejected', $this->asBoss('GET', '/me/regularisation')[1]['data']['requests'][0]['state']);
        self::assertSame('Aucun paiement de 60 000 FCFA sur le relevé', $this->asBoss('GET', '/me/regularisation')[1]['data']['requests'][0]['decision_reason']);
        self::assertSame(201, $this->declare(['idempotency_key' => 'decl-ipes-0009'])[0]->getStatusCode(), 'Une nouvelle déclaration est possible après un rejet.');
    }

    public function testUneDemandeNonConfirmeeExpireEtLeClientEstRelance(): void
    {
        self::assertSame(200, $this->admin('PATCH', '/platform/settings', ['regularisation_request_expiry_days' => 3])[0]->getStatusCode());
        $id = $this->declare()[1]['data']['id'];
        $service = $this->kernel->container()->get(RegularisationRequests::class);
        $this->clock->day('2027-01-25');
        self::assertSame(0, $service->expireStale(), 'Le dernier jour, elle est encore valable.');
        $this->clock->day('2027-01-26');
        self::assertSame('expired', $this->asBoss('GET', '/me/regularisation')[1]['data']['requests'][0]['state']);
        self::assertSame(0, $service->expireStale(), 'Rejouable sans effet double.');
        $reminders = $this->notifier->of('platform_regularisation_expired');
        self::assertCount(1, $reminders);
        self::assertSame('compta@ipes.test', $reminders[0]['email']);
        self::assertSame(201, $this->declare(['idempotency_key' => 'decl-ipes-0003'])[0]->getStatusCode());
        // Un paiement réel arrivé après l'expiration reste confirmable par le super admin.
        $this->admin('POST', '/platform/regularisation/declarations/' . $this->asBoss('GET', '/me/regularisation')[1]['data']['requests'][0]['id'] . '/reject', ['reason' => 'ménage']);
        self::assertSame(200, $this->confirm($id, ['received_amount_xaf' => 60000])[0]->getStatusCode());
        self::assertTrue($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));
    }

    public function testLaPasserelleConfirmeEtDebloqueAutomatiquement(): void
    {
        $id = $this->declare()[1]['data']['id'];
        self::assertSame('regularisation_amount_mismatch', $this->webhookMomo('evt-r1', 50000, self::REF)[1]['data']['outcome'], 'Écart de montant au-delà de la tolérance.');
        self::assertSame('regularisation_phone_mismatch', $this->webhookMomo('evt-r2', 60000, self::REF, '+237 677 00 00 00')[1]['data']['outcome']);
        self::assertSame('unknown_reference', $this->webhookMomo('evt-r3', 60000, 'AUTRE-REF-0001')[1]['data']['outcome']);
        self::assertFalse($this->access()->allowsWrites($this->ipes['tenant'], 'higher'), 'Aucun rapprochement valable : rien n\'est débloqué.');
        $declared = $this->admin('GET', '/platform/regularisation/declarations?filter[state]=declared')[1];
        self::assertSame($id, $declared['data'][0]['id']);
        self::assertNotEmpty($declared['data'][0]['review_note']);

        [$ok, $json] = $this->webhookMomo('evt-r4', 59950, 'om 2027 tx 778899', '699 01 23 45');
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame('regularisation_verified', $json['data']['outcome'], 'Référence, numéro et montant (tolérance 100 FCFA) concordent.');
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame('active', $row['state']);
        self::assertNull($row['regularisation']);
        $request = $this->asBoss('GET', '/me/regularisation')[1]['data']['requests'][0];
        self::assertSame('verified', $request['state']);
        self::assertSame('webhook', $request['verified_via']);
        self::assertSame(59950, $request['confirmed_amount_xaf']);
        self::assertCount(1, $this->notifier->of('platform_regularisation_confirmed'));

        $paidUntil = $row['paid_until'];
        self::assertSame('duplicate', $this->webhookMomo('evt-r4', 59950, self::REF)[1]['data']['outcome']);
        self::assertSame('unknown_reference', $this->webhookMomo('evt-r5', 59950, self::REF)[1]['data']['outcome'], 'Une même référence ne valide qu\'une demande.');
        self::assertSame($paidUntil, $this->entitlement($this->ipes['tenant'])['paid_until'], 'Aucun double crédit.');
        self::assertSame(1, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM platform_payments WHERE status = 'succeeded' AND tenant_id = '" . $this->ipes['tenant'] . "'")->fetchColumn());
    }

    public function testUneMemeReferenceNeValideQuUneDemande(): void
    {
        $ipes = $this->declare()[1]['data']['id'];
        $other = $this->declare(['idempotency_key' => 'decl-other-0001', 'reference' => 'MTN-9988-7766', 'channel' => 'mtn_momo'], true);
        self::assertSame(201, $other[0]->getStatusCode(), (string) $other[0]->getBody());
        self::assertSame(200, $this->confirm($other[1]['data']['id'], ['received_amount_xaf' => 60000])[0]->getStatusCode());

        [$clash, $json] = $this->confirm($ipes, ['received_amount_xaf' => 60000, 'reference' => 'mtn 9988 7766']);
        self::assertSame(409, $clash->getStatusCode(), (string) $clash->getBody());
        self::assertFalse($this->access()->allowsWrites($this->ipes['tenant'], 'higher'), 'La référence d\'un autre client ne débloque pas celui-ci.');
        self::assertSame('declared', $this->admin('GET', '/platform/regularisation/declarations?filter[tenant_id]=' . $this->ipes['tenant'])[1]['data'][0]['state']);

        // Une référence déjà validée ne peut plus être déclarée, même écrite autrement.
        $this->admin('POST', '/platform/regularisation/declarations/' . $ipes . '/reject', ['reason' => 'Référence déjà utilisée']);
        self::assertSame(409, $this->declare(['idempotency_key' => 'decl-ipes-0005', 'reference' => 'MTN-9988-7766'])[0]->getStatusCode());
    }

    public function testListeFiltreeExportEtIsolationEntreClients(): void
    {
        $this->declare();
        $this->declare(['idempotency_key' => 'decl-other-0001', 'reference' => 'MTN-9988-7766', 'channel' => 'mtn_momo'], true);

        [$all, $json] = $this->admin('GET', '/platform/regularisation/declarations');
        self::assertSame(200, $all->getStatusCode());
        self::assertCount(2, $json['data']);
        self::assertSame(2, $json['meta']['counts']['declared']);
        self::assertSame(1, count($this->admin('GET', '/platform/regularisation/declarations?filter[channel]=mtn_momo')[1]['data']));
        self::assertSame(1, count($this->admin('GET', '/platform/regularisation/declarations?filter[tenant_id]=' . $this->ipes['tenant'])[1]['data']));
        self::assertSame(0, count($this->admin('GET', '/platform/regularisation/declarations?filter[state]=verified')[1]['data']));
        self::assertSame(422, $this->admin('GET', '/platform/regularisation/declarations?filter[state]=nimporte')[0]->getStatusCode());
        self::assertStringNotContainsString('699012345', (string) $all->getBody());

        [$csv] = $this->admin('GET', '/platform/regularisation/declarations/export');
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('text/csv', $csv->getHeaderLine('Content-Type'));
        self::assertStringContainsString('IPES de Bonab', (string) $csv->getBody());
        self::assertStringContainsString('+2376*****345', (string) $csv->getBody());
        self::assertStringNotContainsString('699012345', (string) $csv->getBody());

        self::assertSame(403, $this->api('GET', '/platform/regularisation/declarations', 'boss', $this->ipes['tenant'], null, $this->ipes['establishment'])[0]->getStatusCode(), 'Un client ne lit pas la liste de la plateforme.');
        $mine = $this->asBoss('GET', '/me/regularisation')[1]['data']['requests'];
        self::assertCount(1, $mine);
        self::assertSame($this->ipes['tenant'], $mine[0]['tenant_id']);
        $theirs = $this->asBoss('GET', '/me/regularisation', null, $this->other['tenant'])[1]['data']['requests'];
        self::assertCount(1, $theirs);
        self::assertSame($this->other['tenant'], $theirs[0]['tenant_id']);
        self::assertSame(403, $this->asBoss('POST', '/platform/regularisation/declarations/inconnue/confirm', ['received_amount_xaf' => 1])[0]->getStatusCode(), 'Le client est refusé avant toute lecture.');
    }

    public function testLeSuiviDuClientEtLesNumerosDePaiementViennentDesReglages(): void
    {
        $this->declare();
        $view = $this->asBoss('GET', '/me/regularisation')[1]['data'];
        self::assertSame('TRU GROUP', $view['contact']['name']);
        self::assertSame('paiements@trugroup.test', $view['contact']['email']);
        self::assertSame('+237699000001', $view['contact']['phone']);
        self::assertSame([
            ['channel' => 'orange_money', 'phone' => '+237699000002', 'holder' => 'TRU GROUP SARL'],
            ['channel' => 'mtn_momo', 'phone' => '+237670000003', 'holder' => 'TRU GROUP SARL'],
        ], $view['payment_numbers']);
        self::assertSame('declared', $view['requests'][0]['state']);
        self::assertSame(7, $view['expiry_days']);
    }

    public function testAucunNumeroEnClairDansLeJournalNiLesMessages(): void
    {
        $id = $this->declare()[1]['data']['id'];
        $this->confirm($id, ['received_amount_xaf' => 60000]);
        $pdo = $this->connections()->pdo();
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE after_json LIKE '%699012345%' OR before_json LIKE '%699012345%'")->fetchColumn());
        self::assertGreaterThanOrEqual(2, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'platform.regularisation_%' AND tenant_id = 'platform'")->fetchColumn());
        self::assertStringNotContainsString('699012345', json_encode($this->notifier->sent, JSON_THROW_ON_ERROR));
    }
}
