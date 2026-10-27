<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Gate\Application\GateService;
use Scholaris\Modules\Apps\PublicNursery\Gate\Console\PickupAuditCommand;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScope;
use Tests\Feature\PublicNursery\Support\PublicNurseryCase;

/** FR-M05, FR-M08, SC-1 : arrivée, maintien motivé, registre du jour, veille de non-récupération idempotente, contrôle O7. */
final class GateRegisterTest extends PublicNurseryCase
{
    private string $child;
    private string $parent;
    private string $director;

    protected function setUp(): void
    {
        $this->bootPublicNursery('2026-10-08T07:45:00+01:00');
        $this->grantAll('teacher', 'public_nursery.pickup.handover');
        $this->grantAll('director', 'public_schools.school.write');
        $this->child = (string) $this->person('Lina', 'Fictive')['id'];
        $this->parent = (string) $this->person('Mere', 'Fictive')['id'];
        $this->director = (string) $this->person('Dora', 'Directrice')['id'];
        $this->enrol($this->child);
        $this->linkParent($this->child, $this->parent);
        $this->assignDirector($this->director);
    }

    private function gate(): GateService
    {
        return $this->kernel->container()->get(GateService::class);
    }

    public function testArriveeRegistreEtMaintienMotive(): void
    {
        $first = $this->ok('POST', '/public-nursery/pickup/arrivals', 'teacher', ['learner_id' => $this->child, 'school_day' => '2026-10-08']);
        $again = $this->ok('POST', '/public-nursery/pickup/arrivals', 'teacher', ['learner_id' => $this->child, 'school_day' => '2026-10-08']);
        self::assertSame($first['id'], $again['id'], 'Arrivée idempotente.');
        $register = $this->ok('GET', '/public-nursery/register?date=2026-10-08', 'teacher');
        self::assertSame('still_at_school', $register['children'][0]['status']);
        self::assertSame(422, $this->code('POST', '/public-nursery/pickup/holds', 'teacher', self::T, self::E, ['learner_id' => $this->child, 'school_day' => '2026-10-08']), 'Maintien sans motif refusé.');
        $this->ok('POST', '/public-nursery/pickup/holds', 'teacher', ['learner_id' => $this->child, 'school_day' => '2026-10-08', 'remark' => 'Parent prévenu (fictif).']);
        self::assertSame('kept', $this->ok('GET', '/public-nursery/register?date=2026-10-08', 'teacher')['children'][0]['status']);
        $this->scopes->restrictToClasses('teacher', 'section-b');
        self::assertSame(403, $this->code('POST', '/public-nursery/pickup/arrivals', 'teacher', self::T, self::E, ['learner_id' => $this->child]), 'Hors de sa section.');
        self::assertSame([], $this->ok('GET', '/public-nursery/register?date=2026-10-08', 'teacher')['children']);
        $this->grantAll('other-director', 'public_nursery.pickup.handover');
        self::assertSame(404, $this->code('POST', '/public-nursery/pickup/arrivals', 'other-director', 'tenant-other', 'est-other', ['learner_id' => $this->child]), 'Enfant d’un autre client : introuvable.');
    }

    public function testVeilleParentsPuisDirectionUneSeuleFoisEtRienPourUnAbsent(): void
    {
        $absent = (string) $this->person('Absent', 'Fictif')['id'];
        $this->enrol($absent);
        $this->ok('POST', '/public-nursery/pickup/arrivals', 'teacher', ['learner_id' => $this->child, 'school_day' => '2026-10-08']);
        $zone = new \DateTimeZone('Africa/Douala');
        self::assertSame(['parent' => 0, 'director' => 0], $this->gate()->watch(self::T, self::E, new \DateTimeImmutable('2026-10-08T13:50:00', $zone)), 'Fin 13:30 + 30 min : pas encore.');
        self::assertSame(['parent' => 1, 'director' => 0], $this->gate()->watch(self::T, self::E, new \DateTimeImmutable('2026-10-08T14:05:00', $zone)));
        self::assertSame(['parent' => 0, 'director' => 1], $this->gate()->watch(self::T, self::E, new \DateTimeImmutable('2026-10-08T14:20:00', $zone)));
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(['parent' => 0, 'director' => 0], $this->gate()->watch(self::T, self::E, new \DateTimeImmutable('2026-10-08T14:30:00', $zone)), 'Idempotente.');
        }
        self::assertSame(['pn.not06.parent', 'pn.not06.director'], array_column($this->safety->sent, 'template'));
        self::assertSame([$this->parent, $this->director], array_column($this->safety->sent, 'person'));
        $rows = array_column($this->gate()->register(self::T, self::E, SectionScope::wholeEstablishment(), '2026-10-08')['children'], null, 'learner_id');
        $alerts = $rows[$this->child]['alerts'];
        sort($alerts);
        self::assertSame(['director', 'parent'], $alerts);
        self::assertSame([], $rows[$absent]['alerts'], 'Absent : aucune alerte.');
        $this->setting('public_nursery.day.end_time', null);
        self::assertSame(['parent' => 0, 'director' => 0], $this->gate()->watch(self::T, self::E, new \DateTimeImmutable('2026-10-09T23:00:00', $zone)), 'Sans heure de fin : inactive.');
    }

    public function testControleO7SignaleUneRemiseSansAutorisation(): void
    {
        $this->ok('GET', '/public-nursery/register/audit?date=2026-10-08', 'director');
        $this->connections()->pdo()->prepare("INSERT INTO pn_gate_events (id, tenant_id, establishment_id, learner_id, structure_id, school_day, event_type, pickup_id, code_id, declared_name, restriction_id, stale_authorization, origin, client_uuid, occurred_at, recorded_at, actor_id, remark) VALUES (?, ?, ?, ?, 'section-a', '2026-10-08', 'handed_over', NULL, NULL, 'Inconnu', NULL, 0, 'online', NULL, ?, ?, 'injection', NULL)")
            ->execute([(string) new Ulid(), self::T, self::E, $this->child, self::NOW, self::NOW]);
        $audit = $this->ok('GET', '/public-nursery/register/audit?date=2026-10-08', 'director');
        self::assertFalse($audit['ok']);
        self::assertSame('no_authorization', $audit['anomalies'][0]['reason']);
        ob_start();
        $code = $this->kernel->container()->get(PickupAuditCommand::class)->run(['2026-10-08']);
        ob_end_clean();
        self::assertSame(1, $code);
        self::assertSame(403, $this->code('GET', '/public-nursery/register/audit', 'teacher', self::T, self::E));
    }
}
