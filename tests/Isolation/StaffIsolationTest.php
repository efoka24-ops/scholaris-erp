<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\Staff\StaffTestCase;

/** Aucune donnée du personnel ni des vacations ne franchit la frontière entre clients ni entre établissements. */
final class StaffIsolationTest extends StaffTestCase
{
    /** @var array<string, mixed> */
    private array $vac = [];
    /** @var array<string, mixed> */
    private array $contract = [];
    /** @var array<string, mixed> */
    private array $hour = [];
    /** @var array<string, mixed> */
    private array $statement = [];

    protected function setUp(): void
    {
        $this->bootStaff();
        $this->vac = $this->staff('p-prof', 'vacataire', ['category' => 'charge']);
        $this->api('PUT', '/vacation-rates', body: ['category' => 'charge', 'hourly_rate' => 6000]);
        $this->contract = $this->activeContract($this->vac['id']);
        $this->hour = $this->validatedHour($this->vac['id'], 'prof', '2026-10-01', 60, 'td');
        $this->statement = $this->api('POST', '/vacation-statements', body: ['staff_id' => $this->vac['id'], 'period' => '2026-10'])[1]['data'];

        // « mallory » : gestionnaire complet d'un autre client (t2) ; « eve » : gestionnaire complet de e2, autre établissement de t1
        $this->access->grant('mallory', 'e1');
        $this->access->grant('eve', 'e2');
        $this->permissions->grant('mallory', 'staff.read', 'staff.manage', 'vacations.manage', 'vacations.validate');
        $this->permissions->grant('eve', 'staff.read', 'staff.manage', 'vacations.manage', 'vacations.validate');
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>|null}> */
    private function targets(): array
    {
        $staff = '/staff/' . $this->vac['id'];

        return [
            ['GET', $staff, null], ['PATCH', $staff, ['grade' => 'x']],
            ['GET', $staff . '/qualifications', null], ['POST', $staff . '/qualifications', ['title' => 'x']],
            ['GET', $staff . '/assignments', null], ['POST', $staff . '/assignments', ['session_kind' => 'td']],
            ['GET', '/vacation-contracts/' . $this->contract['id'], null], ['PATCH', '/vacation-contracts/' . $this->contract['id'], ['max_minutes' => 5]],
            ['POST', '/vacation-contracts/' . $this->contract['id'] . '/terminate', null],
            ['GET', '/vacation-hours/' . $this->hour['id'], null], ['POST', '/vacation-hours/' . $this->hour['id'] . '/validate', null],
            ['POST', '/vacation-hours/' . $this->hour['id'] . '/reject', ['reason' => 'x']],
            ['GET', '/vacation-statements/' . $this->statement['id'], null], ['POST', '/vacation-statements/' . $this->statement['id'] . '/submit', null],
            ['POST', '/vacation-statements/' . $this->statement['id'] . '/pay', ['paid_on' => '2026-10-08', 'payment_reference' => 'x']],
            ['POST', '/vacation-hours', ['staff_id' => $this->vac['id'], 'held_on' => '2026-10-05', 'minutes' => 60]],
            ['POST', '/vacation-contracts', ['staff_id' => $this->vac['id'], 'starts_on' => '2028-01-01', 'ends_on' => '2028-06-30']],
            ['POST', '/vacation-statements', ['staff_id' => $this->vac['id'], 'period' => '2026-10']],
        ];
    }

    public function testUnGestionnaireDUnAutreClientNeVoitRien(): void
    {
        foreach ($this->targets() as [$method, $path, $body]) {
            self::assertSame(404, $this->code($method, $path, 'mallory', 't2', 'e1', $body), $method . ' ' . $path);
        }
        foreach (['/staff', '/vacation-contracts', '/vacation-hours', '/vacation-statements', '/vacation-rates'] as $list) {
            self::assertSame([], $this->api('GET', $list, 'mallory', 't2')[1]['data'], $list);
        }
    }

    public function testUnGestionnaireDUnAutreEtablissementNeVoitRien(): void
    {
        foreach ($this->targets() as [$method, $path, $body]) {
            // l'affectation à e2 est volontairement possible (multi-établissements) : elle est testée à part
            if ($method === 'POST' && str_ends_with($path, '/assignments')) {
                continue;
            }
            self::assertSame(404, $this->code($method, $path, 'eve', 't1', 'e2', $body), $method . ' ' . $path);
        }
        foreach (['/staff', '/vacation-contracts', '/vacation-hours', '/vacation-statements', '/vacation-rates'] as $list) {
            self::assertSame([], $this->api('GET', $list, 'eve', 't1', 'e2')[1]['data'], $list);
        }
    }

    public function testLesEcrituresInterClientsNeCreentRien(): void
    {
        self::assertSame(404, $this->code('POST', '/staff', 'mallory', 't2', 'e1', ['person_id' => 'p-prof', 'kind' => 'permanent']));
        self::assertSame(201, $this->code('POST', '/staff', 'mallory', 't2', 'e1', ['person_id' => 'p-autre', 'kind' => 'permanent']), 'même identifiant d\'établissement, client différent');
        $pdo = $this->connections()->pdo();

        self::assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM staff_members WHERE tenant_id = 't2'")->fetchColumn());
        self::assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM vacation_hours WHERE tenant_id = 't2'")->fetchColumn());
        self::assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM vacation_contracts WHERE tenant_id = 't2'")->fetchColumn());
    }

    public function testLeTauxDUnEtablissementNEstPasCeluiDUnAutre(): void
    {
        self::assertCount(1, $this->api('GET', '/vacation-rates')[1]['data']);
        $this->api('PUT', '/vacation-rates', 'eve', 't1', 'e2', ['category' => 'charge', 'hourly_rate' => 9999]);

        self::assertSame(6000, $this->api('GET', '/vacation-rates')[1]['data'][0]['hourly_rate']);
        self::assertSame(9999, $this->api('GET', '/vacation-rates', 'eve', 't1', 'e2')[1]['data'][0]['hourly_rate']);
    }

    public function testLAffectationAUnAutreEtablissementOuvreLaFicheMaisPasLesVacations(): void
    {
        self::assertSame(201, $this->code('POST', '/staff/' . $this->vac['id'] . '/assignments', 'eve', 't1', 'e2', ['session_kind' => 'td']));

        self::assertSame(200, $this->code('GET', '/staff/' . $this->vac['id'], 'eve', 't1', 'e2'));
        self::assertSame(404, $this->code('GET', '/vacation-hours/' . $this->hour['id'], 'eve', 't1', 'e2'));
        self::assertSame(404, $this->code('GET', '/vacation-contracts/' . $this->contract['id'], 'eve', 't1', 'e2'));
        self::assertSame([], $this->api('GET', '/vacation-hours', 'eve', 't1', 'e2')[1]['data']);
    }

    public function testSansEtablissementActifToutEstRefuse(): void
    {
        self::assertSame(400, $this->code('GET', '/staff', 'admin', 't1', null));
    }
}
