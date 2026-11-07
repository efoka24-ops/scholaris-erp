<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Workflows\Application\WorkflowService;
use Tests\Support\Billing\BillingTestCase;

/**
 * Circuit « annulation d'un paiement » (billing/billing_reversal) branché sur la résolution : par défaut, le comportement
 * historique exact ; l'administrateur peut confier la validation à la direction ou exiger la confirmation récente ; le demandeur
 * ne valide jamais sa propre demande, quel que soit le réglage ; un réglage ne franchit ni l'établissement ni le client.
 */
final class WorkflowsReversalTest extends BillingTestCase
{
    private string $paymentId;
    private \ArrayObject $recent;

    protected function setUp(): void
    {
        $this->recent = new \ArrayObject();
        $this->bootBilling(definitions: [RecentSecondFactor::class => new class ($this->recent) implements RecentSecondFactor {
            public function __construct(private readonly \ArrayObject $recent)
            {
            }

            public function hasRecent(string $tenantId, string $userId, int $maxAgeSeconds): bool
            {
                return isset($this->recent[$tenantId . ':' . $userId]);
            }
        }]);
        $this->seedIpesGrid();
        $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
        $this->openCash();
        $this->clock->set('2026-10-10T10:00:00+01:00');
        [, $json] = $this->pay('cashier', ['amount_xaf' => 170000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        $this->paymentId = $json['data']['id'];

        // « boss » : direction seule. « cashier3 » : caisse ET direction (pour tenter l'auto-approbation).
        foreach (['boss', 'cashier3'] as $user) {
            $this->access->grant($user, 'e1');
            $this->access->grant($user, 'e2');
        }
        $this->permissions->grant('boss', 'billing.read', 'billing.reversal.approve.direction');
        $this->permissions->grant('cashier3', 'billing.read', 'billing.reversal.request', 'billing.reversal.approve', 'billing.reversal.approve.direction');
    }

    /** @param array<string, array<string, mixed>> $steps */
    private function configure(array $steps, string $level = 'establishment', string $tenant = 't1', string $establishment = 'e1'): void
    {
        $this->container()->get(WorkflowService::class)->override($level, $tenant, $level === 'client' ? null : $establishment, 'billing', 'billing_reversal', ['steps' => $steps], 'Réglage de test.', 'admin', null);
    }

    /** @return array<string, mixed> */
    private function request(string $user = 'cashier'): array
    {
        [$response, $json] = $this->api('POST', '/reversals', $user, ['payment_id' => $this->paymentId, 'reason' => 'Erreur de saisie du montant']);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function decide(string $id, string $user, string $action = 'approve'): array
    {
        [$response, $json] = $this->api('POST', '/reversals/' . $id . '/' . $action, $user);

        return [$response->getStatusCode(), $json];
    }

    public function testLeCircuitEstDeclareAvecSesDefautsHistoriques(): void
    {
        $resolved = $this->container()->get(WorkflowService::class)->resolve('t1', 'e1', 'billing', 'billing_reversal');

        self::assertFalse($resolved->customised);
        self::assertSame('billing.reversal.request', $resolved->step('request')->permission());
        self::assertSame('billing.reversal.approve', $resolved->step('approval')->permission());
        self::assertFalse($resolved->step('approval')->recentConfirmation);
    }

    public function testParDefautLeComportementHistoriqueEstInchange(): void
    {
        $reversal = $this->request();

        self::assertSame(403, $this->decide((string) $reversal['id'], 'boss')[0], 'La direction seule n\'est pas le validateur par défaut.');
        self::assertSame(403, $this->decide((string) $reversal['id'], 'cashier')[0], 'La caisse ne valide pas.');
        $done = $this->decide((string) $reversal['id'], 'supervisor');
        self::assertSame([200, 'approved'], [$done[0], $done[1]['data']['status']]);
        self::assertSame('reversed', $this->rows('SELECT status FROM payments')[0]['status']);
    }

    public function testLaDirectionPeutDevenirLaValidatrice(): void
    {
        $this->configure(['approval' => ['actor' => 'direction']]);
        $reversal = $this->request();

        self::assertSame(403, $this->decide((string) $reversal['id'], 'supervisor')[0], 'Le comptable n\'est plus le validateur.');
        self::assertSame(403, $this->decide((string) $reversal['id'], 'supervisor', 'reject')[0], 'Ni pour refuser.');
        self::assertSame('pending', $this->rows('SELECT status FROM reversals')[0]['status']);
        $done = $this->decide((string) $reversal['id'], 'boss');
        self::assertSame([200, 'approved'], [$done[0], $done[1]['data']['status']]);
        self::assertSame('reversed', $this->rows('SELECT status FROM payments')[0]['status']);
        self::assertSame(1, (int) $this->rows('SELECT COUNT(*) AS n FROM audit_log WHERE action = \'payment.reversed\' AND actor_id = \'boss\'')[0]['n']);
    }

    /** @return array<string, array{0: array<string, array<string, mixed>>}> */
    public static function settings(): array
    {
        return [
            'défaut' => [[]],
            'direction' => [['approval' => ['actor' => 'direction']]],
            'direction et confirmation' => [['approval' => ['actor' => 'direction', 'recent_confirmation' => true]]],
            'comptable et confirmation' => [['approval' => ['recent_confirmation' => true]]],
        ];
    }

    /** @param array<string, array<string, mixed>> $steps */
    #[\PHPUnit\Framework\Attributes\DataProvider('settings')]
    public function testLeDemandeurNeValideJamaisSaDemandeQuelQueSoitLeReglage(array $steps): void
    {
        if ($steps !== []) {
            $this->configure($steps);
        }
        $this->recent['t1:cashier3'] = true;
        $reversal = $this->request('cashier3');

        [$status, $problem] = $this->decide((string) $reversal['id'], 'cashier3');

        self::assertSame(403, $status);
        self::assertStringContainsString('autre personne que le demandeur', (string) $problem['detail']);
        self::assertSame('confirmed', $this->rows('SELECT status FROM payments')[0]['status']);
        self::assertSame('pending', $this->rows('SELECT status FROM reversals')[0]['status']);
    }

    public function testLeReglageNePeutPasLeverLesVerrous(): void
    {
        $service = $this->container()->get(WorkflowService::class);
        foreach ([
            ['request' => ['actor' => 'direction']],
            ['request' => ['enabled' => false]],
            ['approval' => ['enabled' => false]],
            ['approval' => ['actor' => 'inconnu']],
        ] as $steps) {
            try {
                $service->override('establishment', 't1', 'e1', 'billing', 'billing_reversal', ['steps' => $steps], 'Tentative de levée.', 'admin', null);
                self::fail('Un verrou a été levé : ' . json_encode($steps));
            } catch (HttpException $e) {
                self::assertSame(422, $e->status);
            }
        }
        self::assertFalse($service->resolve('t1', 'e1', 'billing', 'billing_reversal')->customised);
    }

    public function testLaConfirmationRecentePeutEtreExigee(): void
    {
        $this->configure(['approval' => ['recent_confirmation' => true]]);
        $reversal = $this->request();

        [$status, $problem] = $this->decide((string) $reversal['id'], 'supervisor');
        self::assertSame([403, 'https://api.scholaris.cm/errors/second-factor-required'], [$status, $problem['type']]);
        self::assertSame('confirmed', $this->rows('SELECT status FROM payments')[0]['status']);
        $this->recent['t1:supervisor'] = true;
        self::assertSame(200, $this->decide((string) $reversal['id'], 'supervisor')[0]);
    }

    public function testLeReglageNeFranchitNiLEtablissementNiLeClient(): void
    {
        $this->configure(['approval' => ['actor' => 'direction']], 'establishment', 't1', 'e2');
        $this->configure(['approval' => ['actor' => 'direction']], 'establishment', 't2', 'x1');

        $workflows = $this->container()->get(WorkflowService::class);
        self::assertSame('billing.reversal.approve', $workflows->resolve('t1', 'e1', 'billing', 'billing_reversal')->step('approval')->permission());
        self::assertSame('billing.reversal.approve.direction', $workflows->resolve('t1', 'e2', 'billing', 'billing_reversal')->step('approval')->permission());
        self::assertSame('billing.reversal.approve.direction', $workflows->resolve('t2', 'x1', 'billing', 'billing_reversal')->step('approval')->permission());

        // Le paiement de l'établissement e1 se valide toujours par le comptable.
        $reversal = $this->request();
        self::assertSame(200, $this->decide((string) $reversal['id'], 'supervisor')[0]);
    }

    public function testLeReglageDuClientSAppliqueAuxEtablissementsSansReglageLocal(): void
    {
        $this->configure(['approval' => ['actor' => 'direction']], 'client');
        $reversal = $this->request();

        self::assertSame(403, $this->decide((string) $reversal['id'], 'supervisor')[0]);
        self::assertSame(200, $this->decide((string) $reversal['id'], 'boss')[0]);
    }
}
