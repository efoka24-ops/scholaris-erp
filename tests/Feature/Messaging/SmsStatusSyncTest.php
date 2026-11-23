<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsStatusLookup;
use Scholaris\Modules\Socle\Messaging\Application\SmsStatusSync;
use Scholaris\Modules\Socle\Messaging\Console\SyncSmsStatusCommand;
use Tests\Support\Messaging\MessagingTestCase;

/** Suivi d'acheminement des SMS : référence mémorisée à l'envoi, puis sent -> delivered/failed. Fournisseur simulé. */
final class SmsStatusSyncTest extends MessagingTestCase
{
    private FakeTrackedSms $provider;

    protected function setUp(): void
    {
        $this->provider = new FakeTrackedSms();
        $this->bootMessaging([SmsGateway::class => $this->provider, SmsStatusLookup::class => $this->provider]);
        $this->parent('p1', 'Marie', '+237670000001');
    }

    private function sendSms(): void
    {
        $this->messenger()->send('t1', 'e1', 'p1', 'payment_receipt', ['receipt_number' => 'R-1', 'amount' => 1000], ['sms']);
    }

    /** @return array{status: string, reference: string|null, delivered_at: string|null, reason: string|null} */
    private function delivery(string $reference): array
    {
        $statement = $this->connections()->pdo()->prepare('SELECT status, provider_reference, delivered_at, reason FROM message_deliveries WHERE provider_reference = ?');
        $statement->execute([$reference]);
        $row = $statement->fetch();

        return ['status' => (string) $row['status'], 'reference' => $row['provider_reference'], 'delivered_at' => $row['delivered_at'], 'reason' => $row['reason']];
    }

    private function sync(): SmsStatusSync
    {
        return $this->kernel->container()->get(SmsStatusSync::class);
    }

    public function testLaReferenceDuFournisseurEstMemoriseeALEnvoi(): void
    {
        $this->sendSms();

        self::assertSame('sent', $this->delivery('teeeotp-1')['status']);
    }

    public function testRemisEtEchecMisAJourEtRienDePlusAuDeuxiemePassage(): void
    {
        $this->parent('p2', 'Paul', '+237670000002');
        $this->messenger()->send('t1', 'e1', 'p2', 'payment_receipt', ['receipt_number' => 'R-2', 'amount' => 2000], ['sms']);
        $this->sendSms();
        $this->parent('p3', 'Luc', '+237670000003');
        $this->messenger()->send('t1', 'e1', 'p3', 'payment_receipt', ['receipt_number' => 'R-3', 'amount' => 3000], ['sms']);
        $this->provider->states = ['teeeotp-1' => SmsStatusLookup::DELIVERED, 'teeeotp-2' => SmsStatusLookup::FAILED, 'teeeotp-3' => SmsStatusLookup::PENDING];

        $result = $this->sync()->run();

        self::assertSame(['checked' => 3, 'delivered' => 1, 'failed' => 1, 'pending' => 1, 'errors' => 0], $result);
        self::assertSame('delivered', $this->delivery('teeeotp-1')['status']);
        self::assertNotNull($this->delivery('teeeotp-1')['delivered_at']);
        self::assertSame(['status' => 'failed', 'reference' => 'teeeotp-2', 'delivered_at' => null, 'reason' => SmsStatusSync::FAILED_REASON], $this->delivery('teeeotp-2'));
        self::assertSame('sent', $this->delivery('teeeotp-3')['status']);

        self::assertSame(1, $this->sync()->run()['checked'], 'Seul le SMS encore « sent » est relu.');
    }

    public function testSansFournisseurSuivantLEtatRienNEstInterroge(): void
    {
        $this->bootMessaging();
        $this->parent('p1', 'Marie', '+237670000001');
        $this->sendSms();

        self::assertSame(['checked' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'errors' => 0], $this->sync()->run());
        self::assertSame('sent', $this->lastDelivery('p1')['status']);
    }

    public function testUneReferenceDUnAutrePrefixeNEstPasSuivie(): void
    {
        $this->provider->sendPrefix = 'http-';
        $this->sendSms();
        $this->provider->states = ['http-1' => SmsStatusLookup::DELIVERED];

        self::assertSame(0, $this->sync()->run()['checked']);
    }

    public function testLesSmsTropAnciensNeSontPlusSuivis(): void
    {
        $this->sendSms();
        $this->provider->states = ['teeeotp-1' => SmsStatusLookup::DELIVERED];
        $this->clock->set('2026-10-15T09:00:00+01:00');

        self::assertSame(0, $this->sync()->run()['checked']);
        self::assertSame('sent', $this->delivery('teeeotp-1')['status']);
    }

    public function testFournisseurInjoignableInterrompAuBoutDeTroisErreurs(): void
    {
        foreach (['p1', 'p2', 'p3', 'p4'] as $i => $id) {
            $this->parent($id, 'Parent' . $i, '+23767000010' . $i);
            $this->messenger()->send('t1', 'e1', $id, 'payment_receipt', ['receipt_number' => 'R-' . $i, 'amount' => 1000], ['sms']);
        }
        $this->provider->down = true;

        $result = $this->sync()->run();

        self::assertSame(3, $result['errors']);
        self::assertSame(3, $result['checked']);
        self::assertStringNotContainsString('+23767000010', $this->logger->dump());
    }

    public function testCommandeAfficheLeBilan(): void
    {
        $this->sendSms();
        $this->provider->states = ['teeeotp-1' => SmsStatusLookup::DELIVERED];
        $command = $this->kernel->container()->get('console.command.sms:sync-status');

        ob_start();
        $code = $command->run(['--limit=10']);
        $output = (string) ob_get_clean();

        self::assertInstanceOf(SyncSmsStatusCommand::class, $command);
        self::assertSame(0, $code);
        self::assertStringContainsString('1 contrôlé(s), 1 remis', $output);

        self::assertSame(2, $command->run(['--bogus']));
    }
}

/** Fournisseur simulé : références « teeeotp-N », états programmés par référence. */
final class FakeTrackedSms implements SmsGateway, SmsStatusLookup
{
    /** @var array<string, string> */
    public array $states = [];
    public bool $down = false;
    public string $sendPrefix = 'teeeotp-';
    private int $counter = 0;

    public function send(string $to, string $message): string
    {
        return $this->sendPrefix . ++$this->counter;
    }

    public function referencePrefix(): ?string
    {
        return 'teeeotp-';
    }

    public function lookup(string $providerReference): ?string
    {
        if ($this->down) {
            throw new \RuntimeException('injoignable');
        }

        return $this->states[$providerReference] ?? SmsStatusLookup::PENDING;
    }
}
