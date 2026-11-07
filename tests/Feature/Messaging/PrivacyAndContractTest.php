<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Psr\Log\LoggerInterface;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogSmsGateway;
use Scholaris\Modules\Socle\Messaging\Application\MessengerImpl;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Tests\Support\Messaging\MessagingTestCase;

/** Aucune donnée personnelle superflue dans les journaux et les réponses ; le contrat Messenger est respecté. */
final class PrivacyAndContractTest extends MessagingTestCase
{
    private const PHONE = '+237670123456';

    public function testLeContratMessengerEstLieAImplementation(): void
    {
        $this->bootMessaging();

        self::assertInstanceOf(MessengerImpl::class, $this->messenger());
        self::assertInstanceOf(Messenger::class, $this->messenger());
        $result = $this->messenger()->send('t1', 'e1', 'inconnue', 'payment_receipt', ['receipt_number' => 'R1', 'amount' => 1]);
        self::assertInstanceOf(DeliveryResult::class, $result);
        self::assertContains($result->status, [DeliveryResult::SENT, DeliveryResult::QUEUED, DeliveryResult::BLOCKED]);
    }

    public function testPersonneInconnueOuAutreClientBloque(): void
    {
        $this->bootMessaging();
        $this->people->add('t2', 'z1', 'Zed', 'Autre', '+237672000009');

        $result = $this->messenger()->send('t1', 'e1', 'z1', 'payment_receipt', ['receipt_number' => 'R1', 'amount' => 1]);

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('consent_missing', $result->reason);
        self::assertSame([], $this->sms->sent);
    }

    public function testLesJournauxEtLesReponsesNeContiennentNiNumeroNiTexte(): void
    {
        $this->bootMessaging();
        $this->people->add('t1', 'p1', 'Marie', 'Foka', self::PHONE, 'marie.foka@example.test');
        $this->api('POST', '/message-templates', 'alice', 't1', 'e1', ['template_key' => 'secret_note', 'locale' => 'fr', 'channel' => 'email', 'body' => 'Confidentiel :amount']);

        $this->messenger()->send('t1', 'e1', 'p1', 'payment_receipt', ['receipt_number' => 'R-SECRET-1', 'amount' => 777]);
        $this->messenger()->send('t1', 'e1', 'p1', 'secret_note', ['amount' => 888], ['email']);
        $created = $this->api('POST', '/messages', 'alice', 't1', 'e1', ['template_key' => 'payment_receipt', 'variables' => ['receipt_number' => 'R2', 'amount' => 5], 'audience' => ['type' => 'person', 'id' => 'p1']]);
        $this->api('POST', '/messages/' . $created[1]['data']['id'] . '/send', 'alice', 't1', 'e1');
        $deliveries = (string) $this->api('GET', '/messages/' . $created[1]['data']['id'] . '/deliveries', 'alice', 't1', 'e1')[0]->getBody();

        $logs = $this->logger->dump();
        self::assertNotSame('', $logs);
        foreach ([self::PHONE, '670123456', 'marie.foka@example.test', 'R-SECRET-1', 'Confidentiel'] as $secret) {
            self::assertStringNotContainsString($secret, $logs, 'Fuite dans les journaux : ' . $secret);
        }
        foreach (['670123456', 'marie.foka@example.test'] as $secret) {
            self::assertStringNotContainsString($secret, $deliveries);
        }
    }

    public function testLeSimulateurSmsMasqueLeNumeroDansLeJournal(): void
    {
        $this->bootMessaging();
        $logger = $this->logger;
        $simulator = new LogSmsGateway($logger);
        $simulator->send(self::PHONE, 'texte confidentiel');

        self::assertStringNotContainsString('670123456', $logger->dump());
        self::assertStringContainsString('3456', $logger->dump());
        self::assertStringNotContainsString('texte confidentiel', $logger->dump());
        self::assertInstanceOf(LoggerInterface::class, $logger);
    }
}
