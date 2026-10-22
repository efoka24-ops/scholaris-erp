<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Pickup;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\ConsistencyCheck;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\PickupWatch;
use Scholaris\Modules\Apps\Nursery\Pickup\Console\ConsistencyCheckCommand;
use Scholaris\Modules\Apps\Nursery\Pickup\Console\PickupWatchCommand;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/** EX-MAT-12 (US3), EX-MAT-15 : veille de non-récupération paramétrée et idempotente ; contrôle de cohérence SC-MAT-1. */
final class WatchTest extends PickupTestCase
{
    private function arrive(string $child, string $section = ''): void
    {
        $this->ok('POST', '/nursery/children/' . $child . '/arrival', ['arrived_at' => '2026-10-21T07:55:00+01:00'], $section === 'b' ? 'educatrice-b' : 'educatrice-a');
        // L'arrivée suppose le droit d'appel.
    }

    /** Vue de l'heure : les durées viennent des paramètres, l'heure de fin est saisie par l'école. */
    private function startAt(string $now, ?string $dayEnd = '16:00'): void
    {
        $this->bootPickup([], $now);
        if ($dayEnd !== null) {
            $this->setSetting(self::TA, self::EA, 'nursery.pickup.day_end_time', $dayEnd);
        }
        $this->arrive($this->ada['child']);
        $this->arrive($this->bao['child'], 'b');
    }

    public function testSansHeureDeFinSaisieParLEcoleAucuneAlertePart(): void
    {
        $this->startAt('2026-10-21T23:00:00+01:00', null);
        $result = $this->service(PickupWatch::class)->run(self::TA, self::EA);
        self::assertSame(['parent' => 0, 'director' => 0], $result);
        self::assertSame([], $this->safety->sent);
    }

    public function testAvantLeDelaiAucuneAlerteApresLeDelaiLesParentsPuisLaDirection(): void
    {
        $this->startAt('2026-10-21T16:20:00+01:00');
        self::assertSame(['parent' => 0, 'director' => 0], $this->service(PickupWatch::class)->run(self::TA, self::EA), 'Fin 16:00 + 30 min : pas encore.');

        $this->startAt('2026-10-21T16:31:00+01:00');
        self::assertSame(['parent' => 2, 'director' => 0], $this->service(PickupWatch::class)->run(self::TA, self::EA));
        self::assertEqualsCanonicalizing([$this->ada['parent'], $this->bao['parent']], $this->safety->recipients());
        self::assertSame('nursery.not02.parent_late', $this->safety->sent[0]['template']);

        $this->startAt('2026-10-21T16:46:00+01:00');
        $watch = $this->service(PickupWatch::class);
        self::assertSame(['parent' => 2, 'director' => 2], $watch->run(self::TA, self::EA));
        self::assertContains($this->directorPerson, $this->safety->recipients());
    }

    public function testUneAlerteParNiveauEnfantEtJourMemeSiLaCommandeTourneChaqueMinute(): void
    {
        $this->startAt('2026-10-21T16:50:00+01:00');
        $watch = $this->service(PickupWatch::class);
        self::assertSame(['parent' => 2, 'director' => 2], $watch->run(self::TA, self::EA));
        $sent = count($this->safety->sent);
        self::assertSame(['parent' => 0, 'director' => 0], $watch->run(self::TA, self::EA));
        self::assertSame(['parent' => 0, 'director' => 0], $watch->run(self::TA, self::EA));
        self::assertCount($sent, $this->safety->sent);
    }

    public function testUnEnfantSortiOuMaintenuEtUnAbsentNeSontJamaisAlertes(): void
    {
        $this->startAt('2026-10-21T16:50:00+01:00');
        $pickup = $this->authorize($this->ada['child']);
        $this->ok('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'educatrice-a');
        $this->ok('POST', '/nursery/pickup/holds', ['learner_id' => $this->bao['child'], 'remark' => 'Gardé à l’école, parent prévenu.'], 'educatrice-b');
        $absent = $this->world->child(self::TA, self::EA, $this->sectionA, 'Cleo');
        self::assertSame(['parent' => 0, 'director' => 0], $this->service(PickupWatch::class)->run(self::TA, self::EA));
        self::assertNotContains($absent['parent'], $this->safety->recipients(), 'Un enfant sans arrivée (absent ou jour de fermeture) n’est jamais alerté.');
    }

    public function testLesDelaisVientDesParametresDeLEcole(): void
    {
        $this->startAt('2026-10-21T16:12:00+01:00');
        $this->setSetting(self::TA, self::EA, 'nursery.pickup.late_after_minutes', 10);
        self::assertSame(['parent' => 2, 'director' => 0], $this->service(PickupWatch::class)->run(self::TA, self::EA));
    }

    public function testUneApplicationSuspendueOuFermeeDesactiveLaVeille(): void
    {
        $this->startAt('2026-10-21T16:50:00+01:00');
        $this->applications->set(self::TA, ApplicationAccess::SUSPENDED);
        self::assertSame(['parent' => 0, 'director' => 0], $this->service(PickupWatch::class)->run(self::TA, self::EA));
    }

    public function testLaCommandeParcourtLesEcolesEtSignaleLesAnomaliesDeCoherence(): void
    {
        $this->startAt('2026-10-21T16:50:00+01:00');
        ob_start();
        $exit = $this->service(PickupWatchCommand::class)->run([]);
        $output = (string) ob_get_clean();
        self::assertSame(0, $exit);
        self::assertStringContainsString('2 alerte(s) aux parents, 2 à la direction', $output);

        $pickup = $this->authorize($this->ada['child']);
        $this->ok('POST', '/nursery/pickup/handovers', ['learner_id' => $this->ada['child'], 'pickup_id' => $pickup], 'educatrice-a');
        ob_start();
        self::assertSame(0, $this->service(ConsistencyCheckCommand::class)->run(['2026-10-21']));
        ob_end_clean();

        // Une remise sans autorisation connue (écriture frauduleuse directe) est détectée.
        $this->service(ConnectionFactory::class)->pdo()->exec("INSERT INTO nu_gate_events (id, tenant_id, establishment_id, learner_id, school_day, event_type, pickup_id, stale_authorization, origin, occurred_at, recorded_at, actor_id) VALUES ('01FRAUD000000000000000001', '" . self::TA . "', '" . self::EA . "', '" . $this->bao['child'] . "', '2026-10-21', 'handed_over', NULL, 0, 'online', '2026-10-21T16:00:00+01:00', '2026-10-21T16:00:00+01:00', 'x')");
        $violations = $this->service(ConsistencyCheck::class)->violations(self::TA, self::EA, '2026-10-21');
        self::assertCount(1, $violations);
        self::assertSame('01FRAUD000000000000000001', $violations[0]['event_id']);
        ob_start();
        self::assertSame(1, $this->service(ConsistencyCheckCommand::class)->run(['2026-10-21']));
        self::assertStringContainsString('1 anomalie', (string) ob_get_clean());
        self::assertSame(2, $this->service(ConsistencyCheckCommand::class)->run(['pas-une-date']));
    }
}
