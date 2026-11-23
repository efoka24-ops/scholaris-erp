<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Tests\Support\Messaging\MessagingTestCase;

final class SafetyMessengerTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        $this->bootMessaging();
        $this->parent('p1', 'Marie', '+237670000001');
    }

    public function testUnParentAyantRefuseLesMessagesNonEssentielsRecoitQuandMemeUneAlerteDeSecurite(): void
    {
        $this->people->revokeConsent('t1', 'p1');

        $normal = $this->messenger()->send('t1', 'e1', 'p1', 'payment_receipt', ['receipt_number' => 'R1', 'amount' => 1000]);
        $safety = $this->kernel->container()->get(SafetyMessenger::class)->send('t1', 'p1', 'pn.not07.parent', [
            'child_name' => 'Lina Meka',
            'date' => '2026-10-16',
            'time' => '17:45',
            'details' => 'Personne non autorisée',
            'reference' => 'PN-ALERT-001',
        ]);

        self::assertSame(DeliveryResult::BLOCKED, $normal->status);
        self::assertSame('consent_missing', $normal->reason);
        self::assertSame(DeliveryResult::SENT, $safety->status);
        self::assertCount(1, $this->sms->sent);
        self::assertSame('+237670000001', $this->sms->sent[0]['to']);
        self::assertStringContainsString('Lina Meka', $this->sms->sent[0]['message']);
        self::assertStringContainsString('PN-ALERT-001', $this->sms->sent[0]['message']);
    }

    public function testDestinataireInconnuResteBloque(): void
    {
        $result = $this->kernel->container()->get(SafetyMessenger::class)->send('t1', 'inconnu', 'pn.not06.parent', ['child_name' => 'X', 'time' => '18:00', 'date' => '2026-10-16', 'establishment_name' => 'Collège Alpha', 'reference' => 'REF']);

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('recipient_unknown', $result->reason);
    }
}