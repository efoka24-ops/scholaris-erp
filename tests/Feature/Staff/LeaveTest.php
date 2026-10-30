<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use Tests\Support\Staff\StaffTestCase;

/** Congés et absences : demande, validation à deux personnes, soldes par type, calendrier, isolation. */
final class LeaveTest extends StaffTestCase
{
    /** @var array<string, mixed> */
    private array $teacher = [];
    /** @var array<string, mixed> */
    private array $other = [];

    protected function setUp(): void
    {
        $this->bootStaff();
        $this->permissions->grant('prof', 'staff.leave.request');
        $this->permissions->grant('prof2', 'staff.leave.request');
        $this->permissions->grant('boss', 'staff.leave.approve');
        $this->permissions->grant('boss2', 'staff.leave.approve');
        $this->teacher = $this->staff('p-prof');
        $this->other = $this->staff('p-prof2');
    }

    /** @return array<string, mixed> */
    private function request(string $user = 'prof', string $type = 'annual', string $from = '2026-10-12', string $to = '2026-10-16'): array
    {
        [$response, $body] = $this->api('POST', '/leave-requests', $user, body: ['leave_type' => $type, 'starts_on' => $from, 'ends_on' => $to, 'reason' => 'Repos']);
        self::assertSame(201, $response->getStatusCode(), json_encode($body));

        return $body['data'];
    }

    public function testLaDemandeCompteLesJoursOuvresEtPasseParApprovals(): void
    {
        $leave = $this->request('prof', 'annual', '2026-10-09', '2026-10-13');
        self::assertSame('pending', $leave['status']);
        self::assertSame(3, $leave['days']);
        self::assertSame($this->teacher['id'], $leave['staff_id']);
        self::assertNotNull($leave['approval_id']);
        self::assertArrayNotHasKey('tenant_id', $leave);
    }

    public function testSansDroitDeDemandeLaCreationEstRefusee(): void
    {
        self::assertSame(403, $this->code('POST', '/leave-requests', 'boss', body: ['leave_type' => 'annual', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-13']));
    }

    public function testOnNePeutPasDemanderPourUnAutre(): void
    {
        self::assertSame(404, $this->code('POST', '/leave-requests', 'prof', body: [
            'staff_id' => $this->other['id'], 'leave_type' => 'annual', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-13',
        ]));
    }

    public function testLeDemandeurNePeutPasValiderMaisUnAutreResponsableLePeut(): void
    {
        $this->permissions->grant('prof', 'staff.leave.approve');
        $leave = $this->request();
        self::assertSame(403, $this->code('POST', '/leave-requests/' . $leave['id'] . '/approve', 'prof'));

        [$response, $body] = $this->api('POST', '/leave-requests/' . $leave['id'] . '/approve', 'boss');
        self::assertSame(200, $response->getStatusCode(), json_encode($body));
        self::assertSame('approved', $body['data']['status']);
        self::assertSame('boss', $body['data']['decided_by']);
        // rejouer la décision : refusé, pas de double effet
        self::assertSame(409, $this->code('POST', '/leave-requests/' . $leave['id'] . '/approve', 'boss2'));
        self::assertSame(409, $this->code('POST', '/leave-requests/' . $leave['id'] . '/reject', 'boss2'));
    }

    public function testSansDroitDeValidationOnNeDecidePas(): void
    {
        $leave = $this->request();
        self::assertSame(403, $this->code('POST', '/leave-requests/' . $leave['id'] . '/approve', 'prof2'));
    }

    public function testLeRefusEstEnregistre(): void
    {
        $leave = $this->request();
        [, $body] = $this->api('POST', '/leave-requests/' . $leave['id'] . '/reject', 'boss');
        self::assertSame('rejected', $body['data']['status']);
    }

    public function testChevauchementEtPeriodeSansJourOuvreRefuses(): void
    {
        $this->request('prof', 'annual', '2026-10-12', '2026-10-16');
        self::assertSame(409, $this->code('POST', '/leave-requests', 'prof', body: ['leave_type' => 'sick', 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-20']));
        self::assertSame(422, $this->code('POST', '/leave-requests', 'prof', body: ['leave_type' => 'sick', 'starts_on' => '2026-10-17', 'ends_on' => '2026-10-18']));
        self::assertSame(422, $this->code('POST', '/leave-requests', 'prof', body: ['leave_type' => 'sick', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-19']));
        self::assertSame(422, $this->code('POST', '/leave-requests', 'prof', body: ['leave_type' => 'inconnu', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-21']));
    }

    public function testLeSoldeParTypePlafonneLesDemandes(): void
    {
        self::assertSame(200, $this->code('PUT', '/leave-balances', 'admin', body: [
            'staff_id' => $this->teacher['id'], 'leave_type' => 'annual', 'leave_year' => 2026, 'entitled_days' => 7,
        ]));
        self::assertSame(403, $this->code('PUT', '/leave-balances', 'prof', body: [
            'staff_id' => $this->teacher['id'], 'leave_type' => 'annual', 'leave_year' => 2026, 'entitled_days' => 99,
        ]));
        $first = $this->request('prof', 'annual', '2026-10-12', '2026-10-16'); // 5 jours
        self::assertSame(409, $this->code('POST', '/leave-requests', 'prof', body: ['leave_type' => 'annual', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-04']));
        // un type sans droit fixé n'est pas plafonné
        $this->request('prof', 'sick', '2026-11-02', '2026-11-13');

        $this->api('POST', '/leave-requests/' . $first['id'] . '/approve', 'boss');
        [, $body] = $this->api('GET', '/leave-balances?year=2026', 'prof');
        $byType = array_column($body['data'], null, 'leave_type');
        self::assertSame(7, $byType['annual']['entitled_days']);
        self::assertSame(5, $byType['annual']['used_days']);
        self::assertSame(2, $byType['annual']['remaining_days']);
        self::assertNull($byType['sick']['remaining_days']);
        self::assertSame(10, $byType['sick']['pending_days']);
    }

    public function testAnnulationParLeDemandeurSeulementEtLibereLeSolde(): void
    {
        $this->api('PUT', '/leave-balances', 'admin', body: ['staff_id' => $this->teacher['id'], 'leave_type' => 'annual', 'leave_year' => 2026, 'entitled_days' => 5]);
        $leave = $this->request();
        self::assertSame(404, $this->code('POST', '/leave-requests/' . $leave['id'] . '/cancel', 'prof2'));
        [, $body] = $this->api('POST', '/leave-requests/' . $leave['id'] . '/cancel', 'prof');
        self::assertSame('cancelled', $body['data']['status']);
        self::assertSame(409, $this->code('POST', '/leave-requests/' . $leave['id'] . '/cancel', 'prof'));
        $this->request();
    }

    public function testVisibiliteEtCalendrier(): void
    {
        $mine = $this->request('prof');
        $this->request('prof2', 'sick', '2026-10-13', '2026-10-14');
        [, $own] = $this->api('GET', '/leave-requests', 'prof');
        self::assertCount(1, $own['data']);
        [, $all] = $this->api('GET', '/leave-requests', 'boss');
        self::assertCount(2, $all['data']);
        self::assertSame(404, $this->code('GET', '/leave-requests/' . $mine['id'], 'prof2'));
        self::assertSame(200, $this->code('GET', '/leave-requests/' . $mine['id'], 'prof'));

        [, $calendar] = $this->api('GET', '/leave-calendar?from=2026-10-01&to=2026-10-31', 'boss');
        self::assertCount(2, $calendar['data']);
        [, $ownCalendar] = $this->api('GET', '/leave-calendar?from=2026-10-01&to=2026-10-31', 'prof2');
        self::assertCount(1, $ownCalendar['data']);
        [, $empty] = $this->api('GET', '/leave-calendar?from=2026-12-01&to=2026-12-31', 'boss');
        self::assertSame([], $empty['data']);
        self::assertSame(422, $this->code('GET', '/leave-calendar?from=2026-12-01&to=2026-11-01', 'boss'));
    }

    public function testIsolationInterClientsEtInterEtablissements(): void
    {
        $leave = $this->request();
        $this->access->grant('mallory', 'e1');
        $this->access->grant('eve', 'e2');
        $this->permissions->grant('mallory', 'staff.manage', 'staff.leave.approve', 'staff.leave.request');
        $this->permissions->grant('eve', 'staff.manage', 'staff.leave.approve', 'staff.leave.request');
        foreach ([['mallory', 't2', 'e1'], ['eve', 't1', 'e2']] as [$user, $tenant, $establishment]) {
            self::assertSame(404, $this->code('GET', '/leave-requests/' . $leave['id'], $user, $tenant, $establishment));
            self::assertSame(404, $this->code('POST', '/leave-requests/' . $leave['id'] . '/approve', $user, $tenant, $establishment));
            [, $list] = $this->api('GET', '/leave-requests', $user, $tenant, $establishment);
            self::assertSame([], $list['data']);
            [, $calendar] = $this->api('GET', '/leave-calendar?from=2026-10-01&to=2026-10-31', $user, $tenant, $establishment);
            self::assertSame([], $calendar['data']);
            self::assertSame(404, $this->code('GET', '/leave-balances?staff_id=' . $this->teacher['id'], $user, $tenant, $establishment));
        }
    }
}
