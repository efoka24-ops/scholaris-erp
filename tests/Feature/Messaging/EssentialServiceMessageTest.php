<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\EssentialMessenger;
use Scholaris\Modules\Socle\Messaging\Contracts\OptOutRegistry;
use Tests\Support\Messaging\MessagingTestCase;

/**
 * D-BUL (décision du 12 novembre 2026) : un message de service essentiel (SMS « bulletin disponible ») part sans consentement
 * recueilli, mais le refus explicite (désinscription du canal, consentement révoqué) est toujours respecté.
 */
final class EssentialServiceMessageTest extends MessagingTestCase
{
    private const TEMPLATE = 'pri.not02.report_card_ready';
    private const VARIABLES = ['child_name' => 'Awa Foka', 'period_label' => 'Trimestre 1', 'link' => '/portail/famille'];

    protected function setUp(): void
    {
        $this->bootMessaging();
        $this->people->add('t1', 'p1', 'Paul', 'Foka', '+237670000001', null, 'fr', false);
    }

    public function testPartSansConsentementRecueilliAlorsQuUnEnvoiOrdinaireEstBloque(): void
    {
        $ordinary = $this->messenger()->send('t1', 'e1', 'p1', self::TEMPLATE, self::VARIABLES);
        self::assertSame(DeliveryResult::BLOCKED, $ordinary->status);
        self::assertSame('consent_missing', $ordinary->reason);

        $essential = $this->essential()->sendEssential('t1', 'e1', 'p1', self::TEMPLATE, self::VARIABLES);

        self::assertSame(DeliveryResult::SENT, $essential->status);
        self::assertCount(1, $this->sms->sent);
        $statement = $this->connections()->pdo()->prepare('SELECT category FROM message_deliveries WHERE status = ?');
        $statement->execute(['sent']);
        self::assertSame('essential', $statement->fetchColumn(), 'Hors plafond annuel des SMS standard.');
    }

    public function testLaDesinscriptionDuCanalEstRespectee(): void
    {
        $this->kernel->container()->get(OptOutRegistry::class)->optOut('t1', 'p1', 'sms');

        $result = $this->essential()->sendEssential('t1', 'e1', 'p1', self::TEMPLATE, self::VARIABLES);

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('opted_out', $result->reason);
        self::assertCount(0, $this->sms->sent);
    }

    public function testLeConsentementRevoqueEstUnRefusExplicite(): void
    {
        $this->connections()->pdo()->prepare(
            'INSERT INTO consents (id, tenant_id, person_id, purpose, granted_at, granted_by, revoked_at, revoked_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute(['01TESTCONSENTREVOKED00001', 't1', 'p1', 'communication', '2026-09-01T08:00:00+01:00', 'u1', '2026-10-01T08:00:00+01:00', 'u1']);

        $result = $this->essential()->sendEssential('t1', 'e1', 'p1', self::TEMPLATE, self::VARIABLES);

        self::assertSame(DeliveryResult::BLOCKED, $result->status);
        self::assertSame('consent_revoked', $result->reason);
        self::assertCount(0, $this->sms->sent);
    }

    private function essential(): EssentialMessenger
    {
        return $this->kernel->container()->get(EssentialMessenger::class);
    }
}
