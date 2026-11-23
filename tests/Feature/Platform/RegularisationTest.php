<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Scholaris\Modules\Socle\Platform\Application\SubscriptionBilling;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Contracts\RegularisationStatus;
use Tests\Support\Platform\PlatformTestCase;

/** Délais par client, options de service, mode régularisation (lecture seule ou bloqué), promesse de paiement et liste « À régulariser ». */
final class RegularisationTest extends PlatformTestCase
{
    /** @var array{tenant: string, establishment: string} */
    private array $ipes;
    /** @var array{tenant: string, establishment: string} */
    private array $other;

    protected function setUp(): void
    {
        $this->bootPlatform();
        $this->ipes = $this->client('IPES de Bonabéri');
        $this->other = $this->client('Institut du Littoral');
        $insert = $this->connections()->pdo()->prepare('INSERT INTO platform_clients (tenant_id, contact_name, contact_email, contact_phone, locale, access_request_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)');
        $insert->execute([$this->ipes['tenant'], 'Direction', 'compta@ipes.test', '+237690000009', 'fr', '2026-10-09T09:00:00+01:00', '2026-10-09T09:00:00+01:00']);
        $this->api('POST', '/applications/higher/open', 'boss', $this->ipes['tenant'], null, $this->ipes['establishment']);
        $this->api('POST', '/applications/higher/open', 'boss', $this->other['tenant'], null, $this->other['establishment']);
    }

    private function access(): ApplicationAccess
    {
        return $this->kernel->container()->get(ApplicationAccess::class);
    }

    private function billing(): SubscriptionBilling
    {
        return $this->kernel->container()->get(SubscriptionBilling::class);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function admin(string $method, string $path, array $body = []): array
    {
        return $this->api($method, '/platform/clients/' . $this->ipes['tenant'] . $path, self::ROOT, null, $body);
    }

    public function testSansSurchargeLeComportementEstCeluiDuCatalogue(): void
    {
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertNull($row['grace_days_override']);
        $this->clock->day('2027-01-22');
        self::assertSame(2, $this->billing()->run()['suspended'], 'Deux clients en retard.');
        $row = $this->entitlement($this->ipes['tenant']);
        self::assertSame('read_only', $row['regularisation'], 'Niveau par défaut de la plateforme : lecture seule.');
        self::assertSame('auto', $row['regularisation_origin']);
        self::assertTrue($this->access()->allowsReads($this->ipes['tenant'], 'higher'));
        self::assertFalse($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));
        self::assertSame('read_only', $this->kernel->container()->get(RegularisationStatus::class)->level($this->ipes['tenant'], 'higher'));
        self::assertCount(1, $this->notifier->of('platform_subscription_suspended'), 'Seul IPES a un contact de facturation.');
    }

    public function testLesDelaisParClientSontLusParLaFacturationEtLeControleDAcces(): void
    {
        self::assertSame(403, $this->api('PATCH', '/platform/clients/' . $this->ipes['tenant'] . '/applications/higher/terms', 'boss', $this->ipes['tenant'], ['grace_days' => 3, 'reason' => 'x'], $this->ipes['establishment'])[0]->getStatusCode());
        self::assertSame(422, $this->admin('PATCH', '/applications/higher/terms', ['grace_days' => 3])[0]->getStatusCode(), 'Motif obligatoire.');
        self::assertSame(422, $this->admin('PATCH', '/applications/higher/terms', ['billing_period_months' => 5, 'reason' => 'x'])[0]->getStatusCode());

        [$ok, $json] = $this->admin('PATCH', '/applications/higher/terms', ['grace_days' => 3, 'trial_days' => 30, 'reason' => 'Accord commercial']);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame(3, $json['data']['terms']['grace_days']);
        self::assertTrue($json['data']['terms']['grace_days_overridden']);
        self::assertSame('2026-11-07', $json['data']['trial_ends_on'], 'Essai de 30 jours à partir du 9 octobre.');

        $this->clock->day('2026-11-09');
        $this->billing()->run();
        self::assertSame('past_due', $this->entitlement($this->ipes['tenant'])['state']);
        self::assertTrue($this->access()->allowsWrites($this->ipes['tenant'], 'higher'));
        $this->clock->day('2026-11-11');
        self::assertSame('suspended', $this->access()->state($this->ipes['tenant'], 'higher'), '3 jours de grâce dépassés au 11/11, sans attendre la commande.');
        self::assertSame('trial', $this->access()->state($this->other['tenant'], 'higher'), 'Un autre client garde ses valeurs.');

        $audit = $this->connections()->pdo()->query("SELECT after_json FROM audit_log WHERE action = 'platform.application_terms_set'")->fetchColumn();
        self::assertIsString($audit);
        self::assertStringContainsString('Accord commercial', $audit);
        self::assertStringContainsString('"before"', $audit);
    }

    public function testPeriodiciteEtOptionsDeServiceCompoSentLeMontantDu(): void
    {
        $this->admin('PATCH', '/applications/higher/terms', ['billing_period_months' => 3, 'reason' => 'Facturation trimestrielle']);
        [$create, $json] = $this->api('POST', '/platform/service-options', self::ROOT, null, ['option_key' => 'sms', 'label_fr' => 'SMS aux parents', 'label_en' => 'Parent SMS', 'monthly_price_xaf' => 5000, 'app_keys' => ['higher']]);
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        self::assertSame(409, $this->api('POST', '/platform/service-options', self::ROOT, null, ['option_key' => 'sms', 'label_fr' => 'SMS', 'label_en' => 'SMS', 'monthly_price_xaf' => 1])[0]->getStatusCode());
        $this->api('POST', '/platform/service-options', self::ROOT, null, ['option_key' => 'portail', 'label_fr' => 'Portail parents', 'label_en' => 'Parent portal', 'monthly_price_xaf' => 8000, 'app_keys' => ['primary']]);

        self::assertSame(422, $this->admin('POST', '/applications/higher/options', ['option_key' => 'portail', 'reason' => 'x'])[0]->getStatusCode(), 'Option réservée à une autre application.');
        [$sub, $app] = $this->admin('POST', '/applications/higher/options', ['option_key' => 'sms', 'price_xaf' => 4000, 'reason' => 'Remise de lancement']);
        self::assertSame(200, $sub->getStatusCode(), (string) $sub->getBody());
        self::assertSame(60000, $app['data']['monthly_price_xaf']);
        self::assertSame(4000, $app['data']['options_total_xaf']);
        self::assertSame(64000, $app['data']['monthly_total_xaf']);

        self::assertSame(422, $this->api('POST', '/applications/higher/payments', 'boss', $this->ipes['tenant'], ['months' => 1, 'channel' => 'mtn_momo', 'payer_phone' => '+237670112233'], $this->ipes['establishment'])[0]->getStatusCode(), 'Trimestrielle : multiple de 3 mois.');
        [$pay, $payment] = $this->api('POST', '/applications/higher/payments', 'boss', $this->ipes['tenant'], ['months' => 3, 'channel' => 'mtn_momo', 'payer_phone' => '+237670112233'], $this->ipes['establishment']);
        self::assertSame(202, $pay->getStatusCode(), (string) $pay->getBody());
        self::assertSame(192000, $payment['data']['amount_xaf'], '(60 000 + 4 000) x 3 mois.');

        self::assertSame(409, $this->api('DELETE', '/platform/service-options/sms', self::ROOT)[0]->getStatusCode(), 'Souscrite : suppression refusée.');
        [$remove, $after] = $this->admin('POST', '/applications/higher/options/sms/remove', ['reason' => 'Résiliation']);
        self::assertSame(200, $remove->getStatusCode());
        self::assertSame(60000, $after['data']['monthly_total_xaf']);
        self::assertSame(200, $this->api('DELETE', '/platform/service-options/sms', self::ROOT)[0]->getStatusCode());
    }

    public function testBlocageAutomatiqueAuNiveauBloqueRendLApplicationInaccessibleJusquAuPaiement(): void
    {
        [$set] = $this->api('PATCH', '/platform/settings', self::ROOT, null, ['regularisation_default_level' => 1]);
        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());
        $this->clock->day('2027-01-22');
        $this->billing()->run();

        $tenant = $this->ipes['tenant'];
        self::assertSame('locked', $this->entitlement($tenant)['regularisation']);
        self::assertFalse($this->access()->allowsReads($tenant, 'higher'), 'Bloqué : ni lecture ni écriture.');
        self::assertFalse($this->access()->allowsWrites($tenant, 'higher'));
        self::assertCount(1, $this->notifier->of('platform_regularisation_locked'));

        [$status, $me] = $this->api('GET', '/me/regularisation', 'boss', $tenant);
        self::assertSame(200, $status->getStatusCode(), (string) $status->getBody());
        self::assertTrue($me['data']['locked']);
        self::assertTrue($me['data']['can_pay']);
        self::assertSame(60000, $me['data']['items'][0]['amount_due_xaf']);
        self::assertSame(16, $me['data']['items'][0]['days_late']);
        self::assertFalse($this->api('GET', '/me/regularisation', 'teacher', $tenant)[1]['data']['can_pay'], 'Seule la direction peut payer.');
        foreach ($this->api('GET', '/me/regularisation', 'boss', $this->other['tenant'])[1]['data']['items'] as $item) {
            self::assertSame($this->other['tenant'], $item['tenant_id'], 'Isolation : rien d\'un autre client.');
        }

        $this->api('POST', '/applications/higher/payments', 'boss', $tenant, ['months' => 1, 'channel' => 'orange_money', 'payer_phone' => '+237690000000'], $this->ipes['establishment']);
        $provider = (string) $this->connections()->pdo()->query('SELECT provider_reference FROM platform_payments')->fetchColumn();
        self::assertSame('succeeded', $this->webhook('evt-r1', 'payment.succeeded', 60000, $provider)[1]['data']['outcome']);
        $row = $this->entitlement($tenant);
        self::assertSame('active', $row['state']);
        self::assertNull($row['regularisation']);
        self::assertTrue($this->access()->allowsReads($tenant, 'higher'));
        self::assertTrue($this->access()->allowsWrites($tenant, 'higher'));
        self::assertCount(1, $this->notifier->of('platform_regularisation_lifted'));
        self::assertSame('ok', $this->api('GET', '/me/regularisation', 'boss', $tenant)[1]['data']['mode']);
    }

    public function testBlocageEtDeblocageManuelsAvecMotifEtPromesseDePaiement(): void
    {
        $tenant = $this->ipes['tenant'];
        self::assertSame(422, $this->admin('POST', '/regularisation/lock', [])[0]->getStatusCode(), 'Motif obligatoire.');
        [$lock, $json] = $this->admin('POST', '/regularisation/lock', ['reason' => 'Trois relances sans réponse', 'level' => 'locked']);
        self::assertSame(200, $lock->getStatusCode(), (string) $lock->getBody());
        self::assertSame('locked', $json['data'][0]['regularisation']['level']);
        self::assertSame('manual', $json['data'][0]['regularisation']['origin']);
        self::assertFalse($this->access()->allowsReads($tenant, 'higher'));
        self::assertSame(409, $this->admin('POST', '/regularisation/lock', ['reason' => 'encore', 'level' => 'locked'])[0]->getStatusCode(), 'Déjà bloqué à ce niveau.');

        // Niveau changé sur place : la lecture revient.
        $this->admin('POST', '/regularisation/level', ['level' => 'read_only', 'reason' => 'Laisser exporter']);
        self::assertTrue($this->access()->allowsReads($tenant, 'higher'));
        self::assertFalse($this->access()->allowsWrites($tenant, 'higher'));

        self::assertSame(422, $this->admin('POST', '/regularisation/delay', ['until' => '2026-10-01', 'reason' => 'x'])[0]->getStatusCode(), 'Date passée.');
        [$delay, $granted] = $this->admin('POST', '/regularisation/delay', ['until' => '2026-10-20', 'reason' => 'Promesse écrite du directeur']);
        self::assertSame(200, $delay->getStatusCode(), (string) $delay->getBody());
        self::assertSame('trial', $granted['data'][0]['state'], 'Bloqué : le délai débloque ; échéance non atteinte, retour à l essai.');
        self::assertTrue($this->access()->allowsWrites($tenant, 'higher'));

        // Retard réel : promesse jusqu'au 25/01, grâce dépassée le 22/01.
        $this->clock->day('2027-01-22');
        $this->admin('POST', '/regularisation/delay', ['until' => '2027-01-31', 'reason' => 'Promesse de paiement']);
        $this->billing()->run();
        self::assertSame('past_due', $this->entitlement($tenant)['state'], 'Promesse en cours : pas de blocage automatique.');
        [$list, $overview] = $this->api('GET', '/platform/regularisation', self::ROOT);
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        self::assertSame('promise', $overview['data'][0]['bucket']);
        self::assertSame(16, $overview['data'][0]['days_late']);

        $this->clock->day('2027-02-02');
        $this->billing()->run();
        self::assertSame('suspended', $this->entitlement($tenant)['state'], 'Promesse échue : blocage automatique.');
        self::assertSame('regularisation', $this->api('GET', '/platform/regularisation', self::ROOT)[1]['data'][0]['bucket']);

        [$unlock, $un] = $this->admin('POST', '/regularisation/unlock', ['reason' => 'Paiement annoncé']);
        self::assertSame(200, $unlock->getStatusCode(), (string) $unlock->getBody());
        self::assertSame('past_due', $un['data'][0]['state']);
        self::assertSame('2027-02-09', $un['data'][0]['promise_until'], 'Déblocage manuel : 7 jours avant de re-bloquer.');
        self::assertTrue($this->access()->allowsWrites($tenant, 'higher'));

        $actions = $this->connections()->pdo()->query("SELECT action FROM audit_log WHERE tenant_id = 'platform' AND action LIKE 'platform.regularisation%'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('platform.regularisation_locked', $actions);
        self::assertContains('platform.regularisation_delay_granted', $actions);
        self::assertContains('platform.regularisation_unlocked', $actions);
    }

    public function testActionsExigentLa2FARecenteEtLExportCsvListeLesClients(): void
    {
        $this->clock->day('2027-01-22');
        $this->billing()->run();
        [$export] = $this->api('GET', '/platform/regularisation/export', self::ROOT);
        self::assertSame(200, $export->getStatusCode());
        self::assertStringContainsString('text/csv', $export->getHeaderLine('Content-Type'));
        self::assertStringContainsString('IPES de Bonab', (string) $export->getBody());

        $this->secondFactor->revoke(self::ROOT);
        self::assertSame(403, $this->admin('POST', '/regularisation/unlock', ['reason' => 'x'])[0]->getStatusCode());
        self::assertSame(403, $this->api('GET', '/platform/regularisation', 'boss', $this->ipes['tenant'])[0]->getStatusCode(), 'Un client ne lit pas la liste de la plateforme.');
    }

    public function testPreavisJ7EstEnvoyeUneSeuleFois(): void
    {
        $service = $this->kernel->container()->get(\Scholaris\Modules\Socle\Platform\Application\RegularisationService::class);
        self::assertSame(0, $service->notifyDueSoon('2026-12-29'), 'J-8.');
        self::assertSame(2, $service->notifyDueSoon('2026-12-30'), 'J-7 : les deux clients, sans toucher aux relances J-14, J-3 et J0.');
        self::assertSame(0, $service->notifyDueSoon('2026-12-31'));
        self::assertCount(1, array_filter($this->notifier->of('platform_subscription_due_soon'), static fn (array $m): bool => $m['email'] === 'compta@ipes.test'));
    }
}
