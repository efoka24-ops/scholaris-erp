<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Tests\Support\Messaging\MessagingTestCase;

/** PP11 : plafond annuel de SMS standard par élève ; les messages essentiels ne comptent pas et ne sont pas refusés. */
final class SmsCapTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        $this->bootMessaging();
        $this->parent('p1', 'Marie', '+237670000001');
        $this->settings->set('messaging.sms_cap_per_learner_year', 3);
        $this->connections()->pdo()->prepare(
            'INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, category, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)',
        )->execute(['01TESTPLATFORMTEMPLATE002', 'platform', 'platform', 'pn.closure', 'fr', 'sms', ':establishment_name fermé demain.', '["establishment_name"]', 'essential', '2026-10-08T09:00:00+01:00', '2026-10-08T09:00:00+01:00']);
    }

    public function testLeSmsStandardAuDelaDuPlafondEstRefuseEtLEssentielPasse(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(DeliveryResult::SENT, $this->standard()->status, 'SMS ' . $i);
        }
        $fourth = $this->standard();
        $essential = $this->messenger()->send('t1', 'e1', 'p1', 'pn.closure', ['establishment_name' => 'Ecole']);

        self::assertSame(DeliveryResult::BLOCKED, $fourth->status);
        self::assertSame('sms_cap_reached', $fourth->reason);
        self::assertSame(DeliveryResult::SENT, $essential->status);
        self::assertCount(4, $this->sms->sent);
    }

    public function testLesMessagesEssentielsNeComptentPasDansLePlafond(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->messenger()->send('t1', 'e1', 'p1', 'pn.closure', ['establishment_name' => 'Ecole']);
        }

        self::assertSame(DeliveryResult::SENT, $this->standard()->status);
    }

    public function testSansPlafondConfigureRienNEstRefuse(): void
    {
        $this->settings->set('messaging.sms_cap_per_learner_year', 0);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(DeliveryResult::SENT, $this->standard()->status);
        }
    }

    private function standard(): DeliveryResult
    {
        return $this->messenger()->send('t1', 'e1', 'p1', 'payment_receipt', ['receipt_number' => 'R1', 'amount' => 1000]);
    }
}
