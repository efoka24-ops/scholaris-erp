<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Tests\Support\Attendance\AttendanceTestCase;

/** T113 : incidents et sanctions avec circuit de validation (proposition par le surveillant, validation par une autre personne). */
final class DisciplineTest extends AttendanceTestCase
{
    protected function setUp(): void
    {
        $this->bootAttendance();
    }

    /** @return array<string, mixed> */
    private function incident(string $person = 's3'): array
    {
        [$response, $json] = $this->api('POST', '/incidents', 'surveillant', ['person_id' => $person, 'occurred_on' => self::DAY, 'category' => 'violence', 'description' => 'Bagarre dans la cour']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /** @return array<string, mixed> */
    private function propose(string $kind = 'blame', ?string $incidentId = null): array
    {
        [$response, $json] = $this->api('POST', '/sanctions', 'surveillant', ['person_id' => 's3', 'kind' => $kind, 'reason' => 'Violence envers un camarade'] + ($incidentId === null ? [] : ['incident_id' => $incidentId]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    public function testLeSurveillantSignaleUnIncident(): void
    {
        $incident = $this->incident();

        self::assertSame(['s3', 'violence', 'surveillant'], [$incident['person_id'], $incident['category'], $incident['reported_by']]);
        [, $list] = $this->api('GET', '/incidents?filter[person_id]=s3', 'censeur');
        [$show] = $this->api('GET', '/incidents/' . $incident['id'], 'surveillant');
        self::assertCount(1, $list['data']);
        self::assertSame(200, $show->getStatusCode());
    }

    public function testUneSanctionProposeeAttendLaValidation(): void
    {
        $incident = $this->incident();
        $sanction = $this->propose('blame', $incident['id']);

        self::assertSame(['proposed', 'surveillant', $incident['id']], [$sanction['status'], $sanction['proposed_by'], $sanction['incident_id']]);
        $approval = $this->rows('SELECT kind, subject_type, status FROM approvals')[0];
        self::assertSame(['sanction.approval', 'sanction', 'pending'], [$approval['kind'], $approval['subject_type'], $approval['status']]);
    }

    public function testLeCenseurValideLaSanction(): void
    {
        $sanction = $this->propose();

        [$response, $json] = $this->api('POST', '/sanctions/' . $sanction['id'] . '/approve', 'censeur');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['approved', 'censeur'], [$json['data']['status'], $json['data']['decided_by']]);
        self::assertSame('approved', $this->rows('SELECT status FROM approvals')[0]['status']);
        [$again] = $this->api('POST', '/sanctions/' . $sanction['id'] . '/reject', 'censeur');
        self::assertSame(409, $again->getStatusCode());
    }

    public function testLeCenseurRefuseLaSanction(): void
    {
        $sanction = $this->propose();

        [, $json] = $this->api('POST', '/sanctions/' . $sanction['id'] . '/reject', 'censeur');

        self::assertSame('rejected', $json['data']['status']);
        self::assertSame('rejected', $this->rows('SELECT status FROM approvals')[0]['status']);
    }

    public function testCeluiQuiProposeNeValidePasLuiMeme(): void
    {
        $this->permissions->grant('surveillant', 'attendance.discipline.approve');
        $sanction = $this->propose();

        [$response] = $this->api('POST', '/sanctions/' . $sanction['id'] . '/approve', 'surveillant');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('proposed', $this->rows('SELECT status FROM sanctions')[0]['status']);
    }

    public function testSansPermissionDeValidationLaValidationEstRefusee(): void
    {
        $sanction = $this->propose();

        foreach (['surveillant', 'reader', 'teacher', 'vs'] as $user) {
            [$response] = $this->api('POST', '/sanctions/' . $sanction['id'] . '/approve', $user);
            self::assertSame(403, $response->getStatusCode(), $user);
        }
    }

    public function testLeBaremeEstCeluiDeLEtablissement(): void
    {
        [$response, $json] = $this->api('POST', '/sanctions', 'surveillant', ['person_id' => 's3', 'kind' => 'bastonnade', 'reason' => 'x']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('avertissement, blame, retenue, exclusion_temporaire, conseil_discipline', $json['errors']['kind'][0]);

        $this->settings->values['attendance.sanction_scale'] = ['travail_interet_general', 'retenue'];
        $sanction = $this->propose('travail_interet_general');
        [$old] = $this->api('POST', '/sanctions', 'surveillant', ['person_id' => 's3', 'kind' => 'blame', 'reason' => 'x']);

        self::assertSame('proposed', $sanction['status']);
        self::assertSame(422, $old->getStatusCode(), 'Le barème de l\'établissement remplace celui par défaut.');
    }

    public function testLIncidentEtLApprenantDoiventExister(): void
    {
        [$unknownPerson] = $this->api('POST', '/incidents', 'surveillant', ['person_id' => 'inconnu', 'occurred_on' => self::DAY, 'category' => 'retard', 'description' => 'x']);
        [$unknownIncident] = $this->api('POST', '/sanctions', 'surveillant', ['person_id' => 's3', 'kind' => 'blame', 'reason' => 'x', 'incident_id' => 'zzz']);
        $other = $this->incident('s1');
        [$mismatch] = $this->api('POST', '/sanctions', 'surveillant', ['person_id' => 's3', 'kind' => 'blame', 'reason' => 'x', 'incident_id' => $other['id']]);
        [$badCategory] = $this->api('POST', '/incidents', 'surveillant', ['person_id' => 's3', 'occurred_on' => self::DAY, 'category' => 'Pas valide!', 'description' => 'x']);

        self::assertSame(404, $unknownPerson->getStatusCode());
        self::assertSame(404, $unknownIncident->getStatusCode());
        self::assertSame(422, $mismatch->getStatusCode());
        self::assertSame(422, $badCategory->getStatusCode());
    }

    public function testLaDisciplineEstReserveeAuxRolesConcernes(): void
    {
        foreach (['teacher', 'parent1', 'nobody', 'reader'] as $user) {
            self::assertSame(403, $this->api('GET', '/incidents', $user)[0]->getStatusCode(), $user . ' lit les incidents');
            self::assertSame(403, $this->api('GET', '/sanctions', $user)[0]->getStatusCode(), $user . ' lit les sanctions');
            self::assertSame(403, $this->api('POST', '/incidents', $user, ['person_id' => 's3', 'occurred_on' => self::DAY, 'category' => 'retard', 'description' => 'x'])[0]->getStatusCode(), $user . ' signale');
        }
        [$approverCannotPropose] = $this->api('POST', '/sanctions', 'censeur', ['person_id' => 's3', 'kind' => 'blame', 'reason' => 'x']);
        self::assertSame(403, $approverCannotPropose->getStatusCode());
        self::assertSame(200, $this->api('GET', '/sanctions', 'censeur')[0]->getStatusCode());
    }

    public function testLesSanctionsSeFiltrentParStatut(): void
    {
        $first = $this->propose();
        $this->propose('retenue');
        $this->api('POST', '/sanctions/' . $first['id'] . '/approve', 'censeur');

        [, $pending] = $this->api('GET', '/sanctions?filter[status]=proposed', 'censeur');

        self::assertSame(['retenue'], array_column($pending['data'], 'kind'));
    }

    public function testLesPersonnesAutoriseesARecupererUnEnfant(): void
    {
        [$day, $json] = $this->api('POST', '/authorized-pickups', 'surveillant', ['learner_id' => 's1', 'pickup_name' => 'Oncle Paul', 'relationship' => 'oncle', 'grant_type' => 'day', 'valid_on' => self::DAY]);
        [$permanent] = $this->api('POST', '/authorized-pickups', 'surveillant', ['learner_id' => 's1', 'pickup_name' => 'Tante Rose', 'relationship' => 'tante', 'grant_type' => 'permanent']);
        [$noDate] = $this->api('POST', '/authorized-pickups', 'surveillant', ['learner_id' => 's1', 'pickup_name' => 'X', 'relationship' => 'ami', 'grant_type' => 'day']);
        [$dated] = $this->api('POST', '/authorized-pickups', 'surveillant', ['learner_id' => 's1', 'pickup_name' => 'X', 'relationship' => 'ami', 'grant_type' => 'permanent', 'valid_on' => self::DAY]);
        [$unknown] = $this->api('POST', '/authorized-pickups', 'surveillant', ['learner_id' => 'inconnu', 'pickup_name' => 'X', 'relationship' => 'ami', 'grant_type' => 'permanent']);
        [$denied] = $this->api('POST', '/authorized-pickups', 'teacher', ['learner_id' => 's1', 'pickup_name' => 'X', 'relationship' => 'ami', 'grant_type' => 'permanent']);

        self::assertSame([201, 201, 422, 422, 404, 403], array_map(static fn ($r): int => $r->getStatusCode(), [$day, $permanent, $noDate, $dated, $unknown, $denied]));

        $service = $this->container()->get(\Scholaris\Modules\Socle\Attendance\Application\PickupService::class);
        self::assertTrue($service->isAuthorized('t1', 'e1', 's1', 'oncle paul', self::DAY));
        self::assertFalse($service->isAuthorized('t1', 'e1', 's1', 'Oncle Paul', '2026-10-09'));
        self::assertTrue($service->isAuthorized('t1', 'e1', 's1', 'Tante Rose', '2026-10-09'));
        self::assertFalse($service->isAuthorized('t1', 'e1', 's1', 'Inconnu', self::DAY));

        [$revoked] = $this->api('POST', '/authorized-pickups/' . $json['data']['id'] . '/revoke', 'surveillant');
        self::assertSame('revoked', $this->api('GET', '/authorized-pickups?filter[status]=revoked', 'surveillant')[1]['data'][0]['status']);
        self::assertSame(200, $revoked->getStatusCode());
        self::assertFalse($service->isAuthorized('t1', 'e1', 's1', 'Oncle Paul', self::DAY));
    }
}
