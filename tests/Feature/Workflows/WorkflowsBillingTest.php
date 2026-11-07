<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Workflows\Application\WorkflowService;
use Tests\Support\Higher\Billing\HigherFinanceTestCase;
use Tests\Support\Workflows\RecentConfirmations;

/**
 * Circuit « bourses » (billing_scholarship) branché sur la résolution : par défaut un second comptable valide ; l'administrateur
 * peut confier la validation à la direction générale ou exiger la confirmation récente ; le demandeur ne valide jamais sa propre
 * demande et le plafond des remises reste appliqué, quel que soit le réglage.
 */
final class WorkflowsBillingTest extends HigherFinanceTestCase
{
    private RecentConfirmations $recent;

    protected function setUp(): void
    {
        $this->bootFinance();
        // Avant le premier appel : les services sont construits à la demande avec la doublure en place.
        $this->recent = new RecentConfirmations();
        $this->kernel->container()->set(RecentSecondFactor::class, $this->recent);
        $this->seedFeeCatalog();
        $this->cdcGrid();
        $this->assign(self::T, 'direction', self::E, 'higher.settings.sensitive', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'direction', self::E, 'billing.manage', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'dg', self::E, 'higher.settings.sensitive', Scope::ESTABLISHMENT);
    }

    /** @param array<string, array<string, mixed>> $steps */
    private function configure(array $steps): void
    {
        $this->service(WorkflowService::class)->override('establishment', self::T, self::E, 'higher', 'billing_scholarship', ['steps' => $steps], 'Réglage de test.', 'admin', null);
    }

    /** @return array<string, mixed> */
    private function request(string $user = 'comptable'): array
    {
        $account = $this->openAccount();
        [$response, $json] = $this->api('POST', '/higher/billing/accounts/' . $account['id'] . '/scholarships', $user, self::T, self::E, ['scholarship_kind' => 'staff', 'rate_value' => 10, 'reason' => 'Enfant du personnel']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function approve(string $id, string $user): array
    {
        [$response, $json] = $this->api('POST', '/higher/billing/scholarships/' . $id . '/approve', $user, self::T, self::E, []);

        return [$response->getStatusCode(), $json];
    }

    public function testParDefautUnSecondComptableValideEtLeDemandeurNeValidePasSaDemande(): void
    {
        $scholarship = $this->request();

        self::assertSame(403, $this->approve((string) $scholarship['id'], 'comptable')[0], 'Le demandeur ne valide pas.');
        self::assertSame(403, $this->approve((string) $scholarship['id'], 'dg')[0], 'La direction générale seule n\'est pas le validateur par défaut.');
        self::assertSame('approved', $this->approve((string) $scholarship['id'], 'comptable2')[1]['data']['status']);
    }

    public function testLaDirectionGeneralePeutDevenirLeValidateur(): void
    {
        $this->configure(['approval' => ['actor' => 'direction_generale']]);
        $scholarship = $this->request();

        self::assertSame(403, $this->approve((string) $scholarship['id'], 'comptable2')[0], 'Le second comptable n\'est plus le validateur.');
        self::assertSame('approved', $this->approve((string) $scholarship['id'], 'dg')[1]['data']['status']);
    }

    public function testLeDemandeurNeValideJamaisMemeDesigneParLeCircuit(): void
    {
        $this->configure(['approval' => ['actor' => 'direction_generale']]);
        $scholarship = $this->request('direction');

        [$status, $problem] = $this->approve((string) $scholarship['id'], 'direction');
        self::assertSame(403, $status);
        self::assertStringContainsString('autre comptable que le demandeur', (string) $problem['detail']);
    }

    public function testLaConfirmationRecentePeutEtreExigee(): void
    {
        $this->configure(['approval' => ['recent_confirmation' => true]]);
        $scholarship = $this->request();

        [$status, $problem] = $this->approve((string) $scholarship['id'], 'comptable2');
        self::assertSame([403, 'https://api.scholaris.cm/errors/second-factor-required'], [$status, $problem['type']]);
        $this->recent->confirm(self::T, 'comptable2');
        [$ok, $body] = $this->approve((string) $scholarship['id'], 'comptable2');
        self::assertSame(200, $ok, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    public function testLePlafondDesRemisesResteAppliqueQuelQueSoitLeCircuit(): void
    {
        $this->configure(['approval' => ['actor' => 'direction_generale']]);
        $account = $this->openAccount();
        $path = '/higher/billing/accounts/' . $account['id'] . '/scholarships';
        [$first] = $this->api('POST', $path, 'comptable', self::T, self::E, ['scholarship_kind' => 'staff', 'rate_value' => 40, 'reason' => 'Enfant du personnel']);
        [$over] = $this->api('POST', $path, 'comptable', self::T, self::E, ['scholarship_kind' => 'merit', 'rate_value' => 20, 'reason' => 'Major']);

        self::assertSame([201, 422], [$first->getStatusCode(), $over->getStatusCode()]);
    }
}
