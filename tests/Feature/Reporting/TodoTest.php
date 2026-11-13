<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use Scholaris\Modules\Socle\Identity\Contracts\TodoProvider as IdentityTodoProvider;
use Scholaris\Modules\Socle\Reporting\Application\TodoAggregator;
use Scholaris\Modules\Socle\Reporting\Contracts\TodoProvider;
use Tests\Support\Reporting\ReportingTestCase;

final class TodoTest extends ReportingTestCase
{
    protected function setUp(): void
    {
        $this->bootReporting(['reporting.todo.test' => [SignatureTodo::class, FailingTodo::class]]);
    }

    public function testLAgregateurRemplaceLaSourceVideDIdentity(): void
    {
        self::assertInstanceOf(TodoAggregator::class, $this->container()->get(IdentityTodoProvider::class));
    }

    public function testApprobationsEnAttenteAvecLienDAction(): void
    {
        $mine = $this->approvals->request('t1', 'payment.reversal', 'payment', 'pay1', 'Doublon', 'cashier');
        $own = $this->approvals->request('t1', 'grade.correction', 'grade', 'g1', 'Erreur', 'boss');
        $done = $this->approvals->request('t1', 'grade.correction', 'grade', 'g2', 'Erreur', 'cashier');
        $this->approvals->approve('t1', $done, 'boss');
        $this->pending->ids['t1|boss'] = [$mine, $own, $done, 'inconnue'];

        $items = $this->container()->get(IdentityTodoProvider::class)->forUser('t1', 'boss');
        $approvals = array_values(array_filter($items, static fn (array $i): bool => $i['kind'] === 'approval'));

        self::assertCount(1, $approvals);
        self::assertSame('Annulation de paiement à approuver', $approvals[0]['title']);
        self::assertSame('/approvals/' . $mine, $approvals[0]['link']);
    }

    public function testSourcesAdditionnellesFusionneesEtTrieesParEcheance(): void
    {
        $id = $this->approvals->request('t1', 'payment.reversal', 'payment', 'pay1', 'Doublon', 'cashier');
        $this->pending->ids['t1|boss'] = [$id];

        $items = $this->container()->get(IdentityTodoProvider::class)->forUser('t1', 'boss');

        self::assertSame(['signature', 'approval'], array_column($items, 'kind'));
    }

    public function testUneSourceDefaillanteNeVidePasLaListe(): void
    {
        self::assertNotSame([], $this->container()->get(IdentityTodoProvider::class)->forUser('t1', 'boss'));
    }

    public function testAucuneTacheNeFuiteVersUnAutreClient(): void
    {
        $id = $this->approvals->request('t1', 'payment.reversal', 'payment', 'pay1', 'Doublon', 'cashier');
        $this->pending->ids['t2|boss'] = [$id];

        $kinds = array_column($this->container()->get(IdentityTodoProvider::class)->forUser('t2', 'boss'), 'kind');

        self::assertNotContains('approval', $kinds);
    }
}

final class SignatureTodo implements TodoProvider
{
    public function forUser(string $tenantId, string $userId): array
    {
        return [['kind' => 'signature', 'title' => 'Signer le PV du conseil de classe', 'due_at' => '2026-10-01T08:00:00+01:00', 'link' => '/documents/pv1']];
    }
}

final class FailingTodo implements TodoProvider
{
    public function forUser(string $tenantId, string $userId): array
    {
        throw new \RuntimeException('indisponible');
    }
}
