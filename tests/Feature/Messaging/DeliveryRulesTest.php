<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Scholaris\Modules\Socle\Messaging\Application\MessageService;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Infrastructure\SmsUsageRepository;
use Tests\Support\Messaging\MessagingTestCase;

/** Consentement, plage 7 h à 21 h, urgence, mise en file, langue du destinataire, comptage des SMS. */
final class DeliveryRulesTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        $this->bootMessaging();
        $this->parent('p1', 'Marie', '+237670000001');
    }

    /** @param array<string, scalar> $variables */
    private function receipt(array $variables = ['receipt_number' => 'R-ALPHA-26-00001', 'amount' => 35000], bool $urgent = false, string $person = 'p1'): DeliveryResult
    {
        return $this->messenger()->send('t1', 'e1', $person, 'payment_receipt', $variables, [], $urgent);
    }

    public function testEnvoiNominalParSmsAvecLeNomDeLEtablissement(): void
    {
        $result = $this->receipt();

        self::assertSame(DeliveryResult::SENT, $result->status);
        self::assertNotNull($result->messageId);
        self::assertCount(1, $this->sms->sent);
        self::assertSame('+237670000001', $this->sms->sent[0]['to']);
        self::assertSame('Reçu R-ALPHA-26-00001 : 35000 FCFA bien reçus. Merci. Collège Alpha', $this->sms->sent[0]['message']);
    }

    public function testConsentementAbsentBloqueLEnvoi(): void
    {
        $this->people->revokeConsent('t1', 'p1');

        $result = $this->receipt();

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('consent_missing', $result->reason);
        self::assertSame([], $this->sms->sent);
        self::assertSame(['status' => 'blocked', 'reason' => 'consent_missing'], $this->lastDelivery('p1'));
    }

    public function testUnMessageUrgentNeContournePasLeConsentement(): void
    {
        $this->people->revokeConsent('t1', 'p1');

        self::assertSame(DeliveryResult::BLOCKED, $this->receipt(urgent: true)->status);
    }

    public function testAucunContactBloque(): void
    {
        $this->people->add('t1', 'p2', 'Paul', 'Foka');

        $result = $this->receipt(person: 'p2');

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('no_contact', $result->reason);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function plages(): iterable
    {
        yield '6 h 59 hors plage' => ['2026-10-08T06:59:00+01:00', DeliveryResult::QUEUED];
        yield '7 h dans la plage' => ['2026-10-08T07:00:00+01:00', DeliveryResult::SENT];
        yield '20 h 59 dans la plage' => ['2026-10-08T20:59:00+01:00', DeliveryResult::SENT];
        yield '21 h hors plage' => ['2026-10-08T21:00:00+01:00', DeliveryResult::QUEUED];
        yield 'minuit hors plage' => ['2026-10-09T00:00:00+01:00', DeliveryResult::QUEUED];
    }

    /** @dataProvider plages */
    public function testPlageHoraire(string $moment, string $expected): void
    {
        $this->clock->set($moment);

        self::assertSame($expected, $this->receipt()->status);
    }

    public function testLeFuseauDeDoualaFaitFoiMemeSiLHorlogeEstEnUtc(): void
    {
        $this->clock->set('2026-10-08T05:30:00+00:00'); // 6 h 30 à Douala

        self::assertSame(DeliveryResult::QUEUED, $this->receipt()->status);
    }

    public function testUnMessageUrgentPasseHorsPlage(): void
    {
        $this->clock->set('2026-10-08T22:30:00+01:00');

        self::assertSame(DeliveryResult::SENT, $this->receipt(urgent: true)->status);
        self::assertCount(1, $this->sms->sent);
    }

    public function testHorsPlageLeMessageEstMisEnFileJusqua7hPuEnvoye(): void
    {
        $this->clock->set('2026-10-08T22:30:00+01:00');
        $result = $this->receipt();
        self::assertSame(DeliveryResult::QUEUED, $result->status);
        self::assertSame([], $this->sms->sent);

        $row = $this->connections()->pdo()->query("SELECT status, not_before FROM message_deliveries WHERE recipient_id = 'p1'")->fetch();
        self::assertSame('queued', $row['status']);
        self::assertSame('2026-10-09T07:00:00+01:00', $row['not_before']);

        $worker = $this->kernel->container()->get(MessageService::class);
        $this->clock->set('2026-10-09T06:59:00+01:00');
        self::assertSame(0, $worker->processPending());
        self::assertSame([], $this->sms->sent);

        $this->clock->set('2026-10-09T07:00:00+01:00');
        self::assertSame(1, $worker->processPending());
        self::assertCount(1, $this->sms->sent);
        self::assertSame('sent', $this->lastDelivery('p1')['status']);
        self::assertSame(0, $worker->processPending());
    }

    public function testAvant7hLaFileVise7hLeJourMeme(): void
    {
        $this->clock->set('2026-10-08T05:00:00+01:00');
        $this->receipt();

        $row = $this->connections()->pdo()->query("SELECT not_before FROM message_deliveries WHERE recipient_id = 'p1'")->fetch();
        self::assertSame('2026-10-08T07:00:00+01:00', $row['not_before']);
    }

    public function testModeleDansLaLangueDuDestinataire(): void
    {
        $this->parent('p3', 'Anna', '+237670000003', 'en');

        $this->receipt(person: 'p3');

        self::assertSame('Receipt R-ALPHA-26-00001 : 35000 FCFA received. Thank you. Collège Alpha', $this->sms->sent[0]['message']);
    }

    public function testRepliSurLeFrancaisQuandLaLangueNAPasDeModele(): void
    {
        $this->parent('p4', 'Ana', '+237670000004', 'en');
        $this->api('POST', '/message-templates', 'alice', 't1', 'e1', ['template_key' => 'custom_note', 'locale' => 'fr', 'channel' => 'sms', 'body' => 'Bonjour :first_name']);

        $this->messenger()->send('t1', 'e1', 'p4', 'custom_note');

        self::assertSame('Bonjour Ana', $this->sms->sent[0]['message']);
    }

    public function testUneLangueInconnueRetombeSurLeFrancais(): void
    {
        $this->parent('p5', 'Eva', '+237670000005', 'es');

        $this->receipt(person: 'p5');

        self::assertStringStartsWith('Reçu ', $this->sms->sent[0]['message']);
    }

    public function testLeModeleDeLEtablissementPrimeSurCeluiDeLaPlateforme(): void
    {
        $this->api('POST', '/message-templates', 'alice', 't1', 'e1', ['template_key' => 'payment_receipt', 'locale' => 'fr', 'channel' => 'sms', 'body' => 'Merci, reçu :receipt_number.']);

        $this->receipt();

        self::assertSame('Merci, reçu R-ALPHA-26-00001.', $this->sms->sent[0]['message']);
    }

    public function testVariablesNonAutoriseesRefusees(): void
    {
        $result = $this->receipt(['receipt_number' => 'R1', 'amount' => 1, 'password' => 'x']);

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('variable_not_allowed', $result->reason);
        self::assertSame([], $this->sms->sent);
    }

    public function testVariableManquanteBloqueAuLieuDEnvoyerUnTexteTronque(): void
    {
        $result = $this->receipt(['amount' => 1]);

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('missing_variable', $result->reason);
    }

    public function testModeleInconnuBloque(): void
    {
        $result = $this->messenger()->send('t1', 'e1', 'p1', 'n_existe_pas');

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('template_missing', $result->reason);
    }

    public function testCanalDemandeSansModeleBloque(): void
    {
        $this->people->add('t1', 'p8', 'Eli', 'Foka', '+237670000008', 'eli@example.test');
        $result = $this->messenger()->send('t1', 'e1', 'p8', 'payment_receipt', ['receipt_number' => 'R1', 'amount' => 1], ['email']);

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('template_missing', $result->reason);
    }

    public function testPanneDeFournisseurNeCasseRienEtNeComptePas(): void
    {
        $this->sms->fail = true;

        $result = $this->receipt();

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('failed', $this->lastDelivery('p1')['status']);
        self::assertSame(0, $this->kernel->container()->get(SmsUsageRepository::class)->count('t1', 'e1', '2026-10'));
    }

    public function testCanalEmailEtNotificationParModeleDeLEtablissement(): void
    {
        $this->people->add('t1', 'p6', 'Luc', 'Foka', '+237670000006', 'luc@example.test');
        $this->api('POST', '/message-templates', 'alice', 't1', 'e1', ['template_key' => 'news', 'locale' => 'fr', 'channel' => 'email', 'subject' => 'Info de :establishment_name', 'body' => 'Bonjour :first_name']);
        $this->api('POST', '/message-templates', 'alice', 't1', 'e1', ['template_key' => 'news', 'locale' => 'fr', 'channel' => 'push', 'body' => 'Bonjour :first_name']);

        $result = $this->messenger()->send('t1', 'e1', 'p6', 'news');

        self::assertSame(DeliveryResult::SENT, $result->status);
        self::assertSame([['to' => 'luc@example.test', 'subject' => 'Info de Collège Alpha', 'body' => 'Bonjour Luc']], $this->mail->sent);
        self::assertSame([], $this->sms->sent);
        $statuses = $this->connections()->pdo()->query("SELECT channel, status FROM message_deliveries WHERE recipient_id = 'p6' ORDER BY channel")->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame(['email' => 'sent', 'push' => 'delivered'], $statuses);
    }

    public function testLeCanalVoixTombeSurSmsQuandLaPasserelleEstDesactivee(): void
    {
        $result = $this->messenger()->send('t1', 'e1', 'p1', 'payment_receipt', ['receipt_number' => 'R-VOICE-1', 'amount' => 1234], ['voice']);

        self::assertSame(DeliveryResult::SENT, $result->status);
        self::assertSame('voice_unavailable', $result->reason);
        self::assertCount(1, $this->sms->sent);
        self::assertSame('+237670000001', $this->sms->sent[0]['to']);
        self::assertStringContainsString('R-VOICE-1', $this->sms->sent[0]['message']);
        $row = $this->connections()->pdo()->query("SELECT channel, status, reason FROM message_deliveries WHERE recipient_id = 'p1' ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame('voice', $row['channel']);
        self::assertSame('sent', $row['status']);
        self::assertSame('voice_unavailable', $row['reason']);
    }

    public function testComptageDesSmsParEtablissementEtParMois(): void
    {
        $this->receipt();
        $this->receipt();
        $this->messenger()->send('t1', 'e2', 'p1', 'payment_receipt', ['receipt_number' => 'R2', 'amount' => 5]);
        $this->clock->set('2026-11-02T09:00:00+01:00');
        $this->receipt();

        $counter = $this->kernel->container()->get(SmsUsageRepository::class);
        self::assertSame(2, $counter->count('t1', 'e1', '2026-10'));
        self::assertSame(1, $counter->count('t1', 'e2', '2026-10'));
        self::assertSame(1, $counter->count('t1', 'e1', '2026-11'));
        self::assertSame(0, $counter->count('t2', 'e1', '2026-10'));

        $usage = $this->api('GET', '/sms-usage', 'alice', 't1', 'e1')[1]['data'];
        self::assertSame([['month' => '2026-11', 'sms_sent' => 1], ['month' => '2026-10', 'sms_sent' => 2]], array_map(static fn (array $r): array => ['month' => $r['month'], 'sms_sent' => (int) $r['sms_sent']], $usage));
    }

    public function testLesSmsBloquesOuEnFileNeSontPasComptes(): void
    {
        $this->people->revokeConsent('t1', 'p1');
        $this->receipt();
        $this->people->add('t1', 'p7', 'Zoe', 'Foka', '+237670000007');
        $this->clock->set('2026-10-08T23:00:00+01:00');
        $this->receipt(person: 'p7');

        self::assertSame(0, $this->kernel->container()->get(SmsUsageRepository::class)->count('t1', 'e1', '2026-10'));
    }

    public function testLaPlageEstParametrable(): void
    {
        $this->settings->set('messaging.window_start_hour', 8);
        $this->settings->set('messaging.window_end_hour', 18);

        $this->clock->set('2026-10-08T07:30:00+01:00');
        self::assertSame(DeliveryResult::QUEUED, $this->receipt()->status);
        $this->clock->set('2026-10-08T18:00:00+01:00');
        self::assertSame(DeliveryResult::QUEUED, $this->receipt()->status);
        $this->clock->set('2026-10-08T17:59:00+01:00');
        self::assertSame(DeliveryResult::SENT, $this->receipt()->status);
    }
}
