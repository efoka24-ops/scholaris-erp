<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMover;
use Tests\Support\Staff\StaffTestCase;

/** PP19 : mutation A -> B, affectations de A closes, rattachement à B, historique. */
final class StaffMoverTest extends StaffTestCase
{
    protected function setUp(): void
    {
        $this->bootStaff();
        $this->establishment('t1', 'e1');
        $this->establishment('t1', 'e2');
        $this->establishment('t2', 'x1');
    }

    public function testMutationClotLesAffectationsDeARattacheBEtConserveLHistorique(): void
    {
        $row = $this->staff('p-prof');
        $path = '/staff/' . $row['id'] . '/assignments';
        $open = $this->api('POST', $path, body: ['session_kind' => 'td', 'planned_minutes' => 600])[1]['data'];
        $dated = $this->api('POST', $path, body: ['session_kind' => 'cm', 'planned_minutes' => 300, 'ends_on' => '2026-10-20'])[1]['data'];

        $move = $this->mover()->move('t1', (string) $row['id'], 'e2', '2026-11-02', 'Mutation d\'office', 'admin');

        self::assertSame('e1', $move->fromEstablishmentId);
        self::assertSame('e2', $move->toEstablishmentId);
        self::assertSame(2, $move->endedAssignments);
        $pdo = $this->connections()->pdo();
        self::assertSame('e2', $pdo->query("SELECT home_establishment_id FROM staff_members WHERE id = '" . $row['id'] . "'")->fetchColumn());
        $assignments = $pdo->query("SELECT id, status, ends_on FROM staff_assignments WHERE staff_id = '" . $row['id'] . "' ORDER BY id")->fetchAll();
        $byId = array_column($assignments, null, 'id');
        self::assertSame('ended', $byId[$open['id']]['status']);
        self::assertSame('2026-11-02', $byId[$open['id']]['ends_on']);
        self::assertSame('ended', $byId[$dated['id']]['status']);
        self::assertSame('2026-10-20', $byId[$dated['id']]['ends_on'], 'Une fin antérieure est conservée.');

        $history = $this->mover()->history('t1', (string) $row['id']);
        self::assertCount(1, $history);
        self::assertSame('Mutation d\'office', $history[0]->reason);
    }

    public function testMutationInvalideEstRefusee(): void
    {
        $row = $this->staff('p-prof');
        $id = (string) $row['id'];

        $this->assertStatus(422, fn () => $this->mover()->move('t1', $id, 'e1', '2026-11-02', null, 'admin'));
        $this->assertStatus(422, fn () => $this->mover()->move('t1', $id, 'e2', '02/11/2026', null, 'admin'));
        $this->assertStatus(404, fn () => $this->mover()->move('t1', $id, 'x1', '2026-11-02', null, 'admin'));
        $this->assertStatus(404, fn () => $this->mover()->move('t2', $id, 'x1', '2026-11-02', null, 'admin'));
        self::assertSame([], $this->mover()->history('t1', $id));

        $this->api('PATCH', '/staff/' . $id, body: ['status' => 'left']);
        $this->assertStatus(409, fn () => $this->mover()->move('t1', $id, 'e2', '2026-11-02', null, 'admin'));
    }

    private function mover(): StaffMover
    {
        return $this->kernel->container()->get(StaffMover::class);
    }

    private function assertStatus(int $status, callable $call): void
    {
        try {
            $call();
        } catch (HttpException $e) {
            self::assertSame($status, $e->status);

            return;
        }
        self::fail('HttpException ' . $status . ' attendue.');
    }

    private function establishment(string $tenant, string $id): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $this->connections()->pdo()->prepare(
            'INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$id, $tenant, 'Ecole ' . $id, 'primary', '{"accent":"#F26B1D","logo":null,"initial":"E","headers":[],"signatories":[],"authorization_numbers":[]}', '[]', $now, $now]);
    }
}
