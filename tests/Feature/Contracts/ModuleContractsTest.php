<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor as AssessmentSecondFactor;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\PendingApprovals;
use Scholaris\Modules\Socle\Identity\Contracts\TodoProvider;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\Portals\Contracts\AdultLearnerCheck;
use Scholaris\Modules\Socle\Reporting\Contracts\PendingApprovalLister;
use Scholaris\Modules\Socle\Scheduling\Infrastructure\PdoActorResolver;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoActorDirectory as StaffActors;
use Tests\Support\AppTestCase;

/** Câblage réel des contrats entre modules : aucune lecture directe de users/people hors Identity et People. */
final class ModuleContractsTest extends AppTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $pdo = $this->connections()->pdo();
        $pdo->exec("INSERT INTO people (id, tenant_id, first_name, last_name, created_at, birth_date) VALUES ('adult', 't1', 'Ada', 'Foka', '2026-01-01', '2000-05-01')");
        $pdo->exec("INSERT INTO people (id, tenant_id, first_name, last_name, created_at, birth_date) VALUES ('minor', 't1', 'Max', 'Foka', '2026-01-01', '2015-05-01')");
        $pdo->exec("INSERT INTO people (id, tenant_id, first_name, last_name, created_at) VALUES ('nodate', 't1', 'Zoe', 'Foka', '2026-01-01')");
        $pdo->exec("INSERT INTO users (id, tenant_id, person_id, locale, status, created_at, updated_at) VALUES ('u1', 't1', 'adult', 'fr', 'active', '2026-01-01', '2026-01-01')");
    }

    public function testBirthInfoDonneLaMajoriteEtLInconnu(): void
    {
        $births = $this->kernel->container()->get(BirthInfo::class);

        self::assertSame('2000-05-01', $births->birthDate('t1', 'adult'));
        self::assertTrue($births->isAdult('t1', 'adult', '2026-10-09'));
        self::assertFalse($births->isAdult('t1', 'minor', '2026-10-09'));
        self::assertTrue($births->isAdult('t1', 'minor', '2033-05-01'), 'Majeur le jour des 18 ans.');
        self::assertFalse($births->isAdult('t1', 'minor', '2033-04-30'));
        self::assertNull($births->isAdult('t1', 'nodate', '2026-10-09'));
        self::assertNull($births->isAdult('t2', 'adult', '2026-10-09'), 'Autre client : inconnu.');
    }

    public function testLesImplementationsParDefautPassentParLesContrats(): void
    {
        $c = $this->kernel->container();

        self::assertSame('adult', $c->get(ActorDirectory::class)->personIdOf('t1', 'u1'));
        self::assertNull($c->get(ActorDirectory::class)->personIdOf('t2', 'u1'));
        self::assertSame('adult', $c->get(PdoActorResolver::class)->personIdOf('t1', 'u1'));
        self::assertSame('adult', $c->get(StaffActors::class)->personIdOf('t1', 'u1'));
        self::assertTrue($c->get(StaffActors::class)->hasAccount('t1', 'adult'));
        self::assertFalse($c->get(StaffActors::class)->hasAccount('t1', 'minor'));
        self::assertTrue($c->get(AdultLearnerCheck::class)->isAdult('t1', 'adult'));
        self::assertFalse($c->get(AdultLearnerCheck::class)->isAdult('t1', 'minor'));
        self::assertNull($c->get(AdultLearnerCheck::class)->isAdult('t1', 'nodate'));
    }

    public function testDeuxiemeFacteurAssessmentRefuseSansPreuveIdentity(): void
    {
        self::assertFalse($this->kernel->container()->get(AssessmentSecondFactor::class)->isRecent('t1', 'u1'));
    }

    public function testApprobationsEnAttenteListeesParAuditEtVisiblesDansLesTaches(): void
    {
        $c = $this->kernel->container();
        $approvals = $c->get(Approvals::class);
        $mine = $approvals->request('t1', 'payment.reversal', 'payment', 'pay1', 'Doublon', 'cashier');
        $own = $approvals->request('t1', 'grade.correction', 'grade', 'g1', 'Erreur', 'boss');
        $done = $approvals->request('t1', 'grade.correction', 'grade', 'g2', 'Erreur', 'cashier');
        $approvals->approve('t1', $done, 'boss');
        $other = $approvals->request('t2', 'payment.reversal', 'payment', 'pay9', 'Doublon', 'cashier');

        self::assertSame([$mine], $c->get(PendingApprovals::class)->pendingIdsFor('t1', 'boss'));
        self::assertSame([$mine], $c->get(PendingApprovalLister::class)->candidateIds('t1', 'boss'));
        self::assertNotContains($own, $c->get(PendingApprovals::class)->pendingIdsFor('t1', 'boss'), 'Jamais ses propres demandes.');
        self::assertNotContains($other, $c->get(PendingApprovals::class)->pendingIdsFor('t1', 'boss'), 'Jamais celles d\'un autre client.');

        $items = $c->get(TodoProvider::class)->forUser('t1', 'boss');
        self::assertSame(['approval'], array_column($items, 'kind'));
        self::assertSame('/approvals/' . $mine, $items[0]['link']);
    }
}
