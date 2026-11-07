<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Organisation\Contracts\ModuleAvailability;
use Scholaris\Modules\Socle\Platform\Application\SubscriptionBilling;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Platform\PlatformTestCase;

/** Libre-service, essai de 90 jours, relances, impayé, suspension, paiement par webhook et réactivation (horloge de test). */
final class SubscriptionTest extends PlatformTestCase
{
    /** @var array{tenant: string, establishment: string} */
    private array $ipes;

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->ipes = $this->client('IPES de Bonabéri');
        $this->connections()->pdo()->prepare('INSERT INTO platform_clients (tenant_id, contact_name, contact_email, contact_phone, locale, access_request_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)')
            ->execute([$this->ipes['tenant'], 'Direction', 'compta@ipes.test', '+237690000009', 'fr', '2026-10-09T09:00:00+01:00', '2026-10-09T09:00:00+01:00']);
    }

    public function testLeClientOuvreLuiMemeUneApplicationEnEssaiEtLesModulesRequisSontActives(): void
    {
        $this->connections()->pdo()->prepare('UPDATE establishments SET modules_enabled = ? WHERE id = ?')->execute(['["people"]', $this->ipes['establishment']]);

        self::assertSame(403, $this->open('teacher')[0]->getStatusCode(), 'Sans applications.manage : refusé.');
        [$response, $json] = $this->open();

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('trial', $json['data']['state']);
        self::assertSame('self_service', $json['data']['source']);
        self::assertSame(90, $json['data']['trial_days_left']);
        self::assertSame(60000, $json['data']['monthly_price_xaf']);
        $modules = $this->kernel->container()->get(ModuleAvailability::class);
        foreach (['people', 'billing', 'assessment', 'reporting'] as $module) {
            self::assertTrue($modules->isEnabled($this->ipes['tenant'], $this->ipes['establishment'], $module), $module);
        }

        self::assertSame(409, $this->open()[0]->getStatusCode(), 'Déjà ouverte.');
        self::assertSame(422, $this->api('POST', '/applications/primary/open', 'boss', $this->ipes['tenant'], null, $this->ipes['establishment'])[0]->getStatusCode(), 'Application annoncée : pas de libre-service.');
        self::assertSame(404, $this->api('POST', '/applications/inconnue/open', 'boss', $this->ipes['tenant'], null, $this->ipes['establishment'])[0]->getStatusCode());
    }

    public function testRelancesJ14J3J0PuisImpayePuisSuspensionAvecHorlogeDeTest(): void
    {
        $this->open();
        $billing = $this->kernel->container()->get(SubscriptionBilling::class);
        $access = $this->kernel->container()->get(ApplicationAccess::class);

        self::assertSame(0, $billing->run('2026-12-22')['reminders_sent'], 'J-15 : rien.');
        self::assertSame(1, $billing->run('2026-12-23')['reminders_sent'], 'J-14.');
        self::assertSame(0, $billing->run('2026-12-23')['reminders_sent'], 'Rejeu du même jour : idempotent.');
        self::assertSame(0, $billing->run('2027-01-02')['reminders_sent'], 'J-4 : rien.');
        self::assertSame(1, $billing->run('2027-01-03')['reminders_sent'], 'J-3.');
        self::assertSame(1, $billing->run('2027-01-06')['reminders_sent'], 'J0.');
        $reminders = $this->notifier->of('platform_subscription_reminder');
        self::assertSame([14, 3, 0], array_map(static fn (array $m): int => (int) $m['variables']['offset'], $reminders));
        self::assertSame('compta@ipes.test', $reminders[0]['email']);
        self::assertSame('06/01/2027', $reminders[0]['variables']['due_on']);

        $this->clock->day('2027-01-07');
        self::assertSame(['date' => '2027-01-07', 'reminders_sent' => 0, 'reminders_undelivered' => 0, 'past_due' => 1, 'suspended' => 0], $billing->run());
        self::assertSame('past_due', $this->entitlement($this->ipes['tenant'])['state']);
        self::assertSame('2027-01-07', $this->entitlement($this->ipes['tenant'])['past_due_since']);
        self::assertTrue($access->allowsWrites($this->ipes['tenant'], 'higher'), 'Délai de grâce : écritures encore permises.');
        self::assertCount(1, $this->notifier->of('platform_subscription_past_due'));

        self::assertSame(0, $billing->run('2027-01-21')['suspended'], '15 jours de grâce : pas encore suspendue.');
        $this->clock->day('2027-01-22');
        self::assertSame(1, $billing->run()['suspended']);
        self::assertSame(0, $billing->run()['suspended'], 'Rejeu : idempotent.');
        self::assertSame('suspended', $this->entitlement($this->ipes['tenant'])['state']);
        self::assertFalse($access->allowsWrites($this->ipes['tenant'], 'higher'), 'Suspendue : écritures refusées.');
        self::assertTrue($access->allowsReads($this->ipes['tenant'], 'higher'), 'Suspendue : lecture et export conservés.');
        self::assertCount(1, $this->notifier->of('platform_subscription_suspended'));

        [$response, $json] = $this->api('GET', '/me/applications', 'teacher', $this->ipes['tenant']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('suspended', $json['data'][0]['state']);
        self::assertFalse($json['data'][0]['writable']);
        self::assertTrue($json['data'][0]['readable']);
    }

    public function testLeControleDAccesSuspendMemeSansPassageDeLaCommandeQuotidienne(): void
    {
        $this->open();
        $this->clock->day('2027-02-01');

        $access = $this->kernel->container()->get(ApplicationAccess::class);
        self::assertSame('suspended', $access->state($this->ipes['tenant'], 'higher'));
        self::assertFalse($access->allowsWrites($this->ipes['tenant'], 'higher'));
        self::assertNull($access->state($this->ipes['tenant'], 'primary'));
        self::assertFalse($access->allowsReads($this->ipes['tenant'], 'primary'));
    }

    public function testLePaiementConfirmeParWebhookReactiveEtLeRejeuNeCrediteQuUneFois(): void
    {
        $this->open();
        $this->clock->day('2027-02-01');
        $this->kernel->container()->get(SubscriptionBilling::class)->run();
        self::assertSame('suspended', $this->entitlement($this->ipes['tenant'])['state']);

        [$response, $json] = $this->pay(['months' => 2, 'channel' => 'mtn_momo', 'payer_phone' => '+237 670 11 22 33'], 'cle-paiement-0001');
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(120000, $json['data']['amount_xaf']);
        self::assertSame('pending', $json['data']['status']);
        [$again] = $this->pay(['months' => 2, 'channel' => 'mtn_momo', 'payer_phone' => '+237670112233'], 'cle-paiement-0001');
        self::assertSame(200, $again->getStatusCode(), 'Même clé d\'idempotence : même paiement.');
        self::assertSame(1, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM platform_payments')->fetchColumn());
        $provider = (string) $this->connections()->pdo()->query('SELECT provider_reference FROM platform_payments')->fetchColumn();

        self::assertSame(401, $this->webhook('evt-1', 'payment.succeeded', 120000, $provider, 'mauvais-secret')[0]->getStatusCode());
        self::assertSame('amount_mismatch', $this->webhook('evt-0', 'payment.succeeded', 1000, $provider)[1]['data']['outcome']);

        [$response, $json] = $this->webhook('evt-1', 'payment.succeeded', 120000, $provider);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('succeeded', $json['data']['outcome']);
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame('active', $row['state']);
        self::assertSame('2027-03-31', $row['paid_until'], 'Deux mois à partir du jour du paiement (1er février).');
        self::assertNull($row['suspended_at']);

        self::assertSame('duplicate', $this->webhook('evt-1', 'payment.succeeded', 120000, $provider)[1]['data']['outcome']);
        self::assertSame('already_succeeded', $this->webhook('evt-2', 'payment.succeeded', 120000, $provider)[1]['data']['outcome']);
        self::assertSame('2027-03-31', $this->entitlement($this->ipes['tenant'])['paid_until'], 'Aucun double crédit.');
        self::assertSame('unknown_reference', $this->webhook('evt-3', 'payment.succeeded', 5000, 'sim-pay-inconnu')[1]['data']['outcome']);

        $payments = $this->api('GET', '/applications/higher/payments', 'boss', $this->ipes['tenant'], null, $this->ipes['establishment'])[1];
        $paymentId = $payments['data'][0]['id'];
        self::assertSame('SCH-2027-000001', $payments['data'][0]['receipt_number']);
        [$response, $receipt] = $this->api('GET', '/application-payments/' . $paymentId . '/receipt', 'boss', $this->ipes['tenant'], null, $this->ipes['establishment']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(120000, $receipt['data']['amount_xaf']);
        self::assertSame('2027-03-31', $receipt['data']['covered_until']);
        self::assertCount(1, $this->notifier->of('platform_payment_receipt'));
        self::assertTrue($this->kernel->container()->get(ApplicationAccess::class)->allowsWrites($this->ipes['tenant'], 'higher'));
    }

    public function testUnPaiementAnticipePendantLEssaiCommenceApresLaFinDeLEssai(): void
    {
        $this->open();
        $this->pay(['months' => 1, 'channel' => 'orange_money', 'payer_phone' => '+237690000000']);
        $provider = (string) $this->connections()->pdo()->query('SELECT provider_reference FROM platform_payments')->fetchColumn();
        $this->webhook('evt-a', 'payment.succeeded', 60000, $provider);

        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame('active', $row['state']);
        self::assertSame('2027-02-06', $row['paid_until'], 'Couvre du 7 janvier au 6 février.');
    }

    public function testUnPaiementEchoueNeChangeRien(): void
    {
        $this->open();
        $this->pay(['months' => 1, 'channel' => 'orange_money', 'payer_phone' => '+237690000000']);
        $provider = (string) $this->connections()->pdo()->query('SELECT provider_reference FROM platform_payments')->fetchColumn();

        self::assertSame('failed', $this->webhook('evt-f', 'payment.failed', 60000, $provider)[1]['data']['outcome']);
        self::assertSame('trial', $this->entitlement($this->ipes['tenant'])['state']);
        self::assertSame(422, $this->pay(['months' => 13, 'channel' => 'orange_money', 'payer_phone' => '+237690000000'])[0]->getStatusCode());
        self::assertSame(422, $this->pay(['months' => 1, 'channel' => 'cash', 'payer_phone' => '+237690000000'])[0]->getStatusCode());
    }

    public function testLAdministrateurDePlateformeSuspendFermeReactiveEtFixeUnTarifPropre(): void
    {
        $this->open();
        $base = '/platform/clients/' . $this->ipes['tenant'] . '/applications/higher';

        [$response, $json] = $this->api('PATCH', $base, self::ROOT, null, ['monthly_price_xaf' => 45000]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(45000, $json['data']['monthly_price_xaf']);
        self::assertTrue($json['data']['price_overridden']);
        self::assertSame(45000, $this->pay(['months' => 1, 'channel' => 'mtn_momo', 'payer_phone' => '+237670000000'])[1]['data']['amount_xaf']);

        self::assertSame(422, $this->api('POST', $base . '/suspend', self::ROOT, null, [])[0]->getStatusCode(), 'Motif obligatoire.');
        self::assertSame('suspended', $this->api('POST', $base . '/suspend', self::ROOT, null, ['reason' => 'Contrôle de conformité.'])[1]['data']['state']);
        self::assertFalse($this->kernel->container()->get(ApplicationAccess::class)->allowsWrites($this->ipes['tenant'], 'higher'));
        [$response, $json] = $this->api('POST', $base . '/reactivate', self::ROOT, null, ['until' => '2027-06-30']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('active', $json['data']['state']);
        self::assertSame('2027-06-30', $json['data']['paid_until']);
        self::assertSame(409, $this->api('POST', $base . '/reactivate', self::ROOT, null, [])[0]->getStatusCode());

        self::assertSame('closed', $this->api('POST', $base . '/close', self::ROOT, null, ['reason' => 'Fin de contrat.'])[1]['data']['state']);
        self::assertFalse($this->kernel->container()->get(ApplicationAccess::class)->allowsReads($this->ipes['tenant'], 'higher'));
        self::assertSame(409, $this->pay(['months' => 1, 'channel' => 'mtn_momo', 'payer_phone' => '+237670000000'])[0]->getStatusCode());

        [$response, $json] = $this->api('POST', '/platform/clients/' . $this->ipes['tenant'] . '/applications', self::ROOT, null, ['app_key' => 'higher']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('active', $json['data']['state'], 'Réouverture : la période payée jusqu\'au 30 juin est conservée, l\'essai n\'est pas renouvelé.');
        self::assertSame('platform_admin', $json['data']['source']);

        [$response, $json] = $this->api('POST', '/platform/clients/' . $this->ipes['tenant'] . '/applications', self::ROOT, null, ['app_key' => 'primary']);
        self::assertSame(201, $response->getStatusCode(), 'L\'administrateur peut ouvrir une application annoncée (pilote).');
        self::assertSame('trial', $json['data']['state']);
    }

    /** @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>} */
    private function open(string $user = 'boss'): array
    {
        return $this->api('POST', '/applications/higher/open', $user, $this->ipes['tenant'], null, $this->ipes['establishment']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function pay(array $body, ?string $key = null): array
    {
        return $this->api('POST', '/applications/higher/payments', 'boss', $this->ipes['tenant'], $body, $this->ipes['establishment'], $key === null ? [] : ['Idempotency-Key' => $key]);
    }
}
