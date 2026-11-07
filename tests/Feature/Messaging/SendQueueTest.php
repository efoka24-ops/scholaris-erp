<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Scholaris\Modules\Socle\Messaging\Application\SendQueue;
use Scholaris\Modules\Socle\Messaging\Console\SendQueueCommand;
use Scholaris\Modules\Socle\Messaging\Domain\RetryBackoff;
use Tests\Support\Messaging\MessagingTestCase;

/** Reprise des envois en échec : backoff exponentiel, plafond de tentatives, reprise unique, commande console. */
final class SendQueueTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        $this->bootMessaging();
        $this->parent('p1', 'Marie', '+237670000001');
    }

    private function queue(): SendQueue
    {
        return $this->kernel->container()->get(SendQueue::class);
    }

    /** @return array{status: string, attempts: int, reason: string|null} */
    private function delivery(): array
    {
        $row = $this->connections()->pdo()->query("SELECT status, attempts, reason FROM message_deliveries WHERE recipient_id = 'p1' ORDER BY id DESC LIMIT 1")->fetch();

        return ['status' => (string) $row['status'], 'attempts' => (int) $row['attempts'], 'reason' => $row['reason'] === null ? null : (string) $row['reason']];
    }

    private function failOnce(): void
    {
        $this->sms->fail = true;
        $this->messenger()->send('t1', 'e1', 'p1', 'payment_receipt', ['receipt_number' => 'R-1', 'amount' => 1000], ['sms']);
    }

    public function testBackoffExponentielPlafonne(): void
    {
        $backoff = new RetryBackoff(60, 600);

        self::assertSame([60, 120, 240, 480, 600, 600], array_map($backoff->delaySeconds(...), [1, 2, 3, 4, 5, 40]));
    }

    public function testUnEchecEstRepris_ApresLAttente_PuisEnvoye(): void
    {
        $this->failOnce();
        self::assertSame(['status' => 'failed', 'attempts' => 1, 'reason' => 'gateway_error'], $this->delivery());

        $this->sms->fail = false;
        self::assertSame(0, $this->queue()->run()['retried'], 'trop tôt : l\'attente de 60 s n\'est pas écoulée');
        self::assertSame([], $this->sms->sent);

        $this->clock->set('2026-10-08T09:01:01+01:00');
        $result = $this->queue()->run();

        self::assertSame(1, $result['retried']);
        self::assertSame(1, $result['processed']);
        self::assertCount(1, $this->sms->sent);
        self::assertSame('sent', $this->delivery()['status']);
        self::assertSame(0, $this->queue()->run()['retried'], 'une livraison envoyée n\'est plus reprise');
        self::assertCount(1, $this->sms->sent);
    }

    public function testLAttenteDoubleACHaqueEchec(): void
    {
        $this->failOnce();
        $this->clock->set('2026-10-08T09:01:01+01:00');
        $this->queue()->run();
        self::assertSame(2, $this->delivery()['attempts']);

        $this->clock->set('2026-10-08T09:02:30+01:00');
        self::assertSame(0, $this->queue()->run()['retried'], '2e échec à 09:01:01 : attente de 120 s');
        $this->clock->set('2026-10-08T09:03:02+01:00');
        self::assertSame(1, $this->queue()->run()['retried']);
        self::assertSame(3, $this->delivery()['attempts']);
    }

    public function testAbandonApresLeNombreMaximalDeTentatives(): void
    {
        $this->failOnce();
        $moment = strtotime('2026-10-08T09:00:00+01:00');
        foreach ([61, 121, 241, 481] as $wait) {
            $moment += $wait;
            $this->clock->set(date(DATE_ATOM, $moment));
            $this->queue()->run();
        }
        self::assertSame(5, $this->delivery()['attempts']);

        $this->clock->set(date(DATE_ATOM, $moment + 5000));
        $result = $this->queue()->run();

        self::assertSame(1, $result['abandoned']);
        self::assertSame(0, $result['retried']);
        self::assertSame(['status' => 'failed', 'attempts' => 5, 'reason' => 'retries_exhausted'], $this->delivery());
        self::assertSame(0, $this->queue()->run()['abandoned'], 'abandon compté une seule fois');
    }

    public function testLaCommandeAfficheLeBilanEtRefuseLesOptionsInconnues(): void
    {
        $this->failOnce();
        $this->clock->set('2026-10-08T09:01:01+01:00');
        $this->sms->fail = false;
        $command = $this->kernel->container()->get(SendQueueCommand::class);

        ob_start();
        $code = $command->run(['--limit=10']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('1 livraison(s) traitée(s), 1 reprise(s), 0 abandon(s)', $output);
        self::assertSame(2, $command->run(['--bogus']));
    }
}
