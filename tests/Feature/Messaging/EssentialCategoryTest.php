<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\OptOutRegistry;
use Tests\Support\Messaging\MessagingTestCase;

/** PP10 / RG-COM-2 : la catégorie « essential » traverse la désinscription, un message standard non. */
final class EssentialCategoryTest extends MessagingTestCase
{
    protected function setUp(): void
    {
        $this->bootMessaging();
        $this->parent('p1', 'Marie', '+237670000001');
        $this->platformTemplate('pn.closure', 'essential');
    }

    public function testUnParentDesinscritRecoitLEssentielMaisPasUnMessageStandard(): void
    {
        $this->kernel->container()->get(OptOutRegistry::class)->optOut('t1', 'p1', 'sms');

        $standard = $this->messenger()->send('t1', 'e1', 'p1', 'payment_receipt', ['receipt_number' => 'R1', 'amount' => 1000]);
        $essential = $this->messenger()->send('t1', 'e1', 'p1', 'pn.closure', ['establishment_name' => 'Ecole']);

        self::assertSame(DeliveryResult::BLOCKED, $standard->status);
        self::assertSame('opted_out', $standard->reason);
        self::assertSame(DeliveryResult::SENT, $essential->status);
        self::assertCount(1, $this->sms->sent);
    }

    public function testLaReinscriptionRetabliLesMessagesStandard(): void
    {
        $registry = $this->kernel->container()->get(OptOutRegistry::class);
        $registry->optOut('t1', 'p1', 'sms');
        $registry->optIn('t1', 'p1', 'sms');

        self::assertFalse($registry->isOptedOut('t1', 'p1', 'sms'));
        self::assertSame(DeliveryResult::SENT, $this->messenger()->send('t1', 'e1', 'p1', 'payment_receipt', ['receipt_number' => 'R1', 'amount' => 1000])->status);
    }

    public function testUnEtablissementNePeutPasClasserUnModeleEnEssentiel(): void
    {
        $this->kernel->container()->get(OptOutRegistry::class)->optOut('t1', 'p1', 'sms');
        $this->connections()->pdo()->prepare(
            'INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)',
        )->execute(['01TESTSCHOOLTEMPLATE00001', 't1', 'e1', 'school.promo', 'fr', 'sms', 'Promo', '[]', '2026-10-08T09:00:00+01:00', '2026-10-08T09:00:00+01:00']);

        $result = $this->messenger()->send('t1', 'e1', 'p1', 'school.promo');

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('opted_out', $result->reason);
    }

    private function platformTemplate(string $key, string $category): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO message_templates (id, tenant_id, establishment_id, template_key, locale, channel, subject, body, variables, category, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)',
        )->execute(['01TESTPLATFORMTEMPLATE001', 'platform', 'platform', $key, 'fr', 'sms', ':establishment_name fermé demain.', '["establishment_name"]', $category, '2026-10-08T09:00:00+01:00', '2026-10-08T09:00:00+01:00']);
    }
}
