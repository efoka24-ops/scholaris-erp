<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;
use Tests\Support\Staff\StaffTestCase;

/** Dossier du personnel : fiches, qualifications, affectations, annuaire. */
final class StaffRecordTest extends StaffTestCase
{
    protected function setUp(): void
    {
        $this->bootStaff();
    }

    public function testCreationEtLectureDUneFicheAvecIdentiteDePeople(): void
    {
        $row = $this->staff('p-prof', 'permanent', ['grade' => 'Professeur certifié', 'category' => 'certifie', 'hired_on' => '2020-09-01']);

        self::assertSame('Jean Mbarga', $row['full_name']);
        self::assertSame('active', $row['status']);
        self::assertSame('e1', $row['home_establishment_id']);
        self::assertArrayNotHasKey('tenant_id', $row);
        self::assertSame($row['id'], $this->api('GET', '/staff/' . $row['id'])[1]['data']['id']);
        self::assertCount(1, $this->api('GET', '/staff')[1]['data']);
    }

    public function testUnePersonneInconnueOuDejaFicheeEstRefusee(): void
    {
        $this->staff('p-prof');

        self::assertSame(409, $this->code('POST', '/staff', body: ['person_id' => 'p-prof', 'kind' => 'vacataire']));
        self::assertSame(404, $this->code('POST', '/staff', body: ['person_id' => 'inconnue', 'kind' => 'permanent']));
        self::assertSame(404, $this->code('POST', '/staff', body: ['person_id' => 'p-autre', 'kind' => 'permanent']));
        self::assertSame(422, $this->code('POST', '/staff', body: ['person_id' => 'p-vac', 'kind' => 'stagiaire']));
        self::assertSame(422, $this->code('POST', '/staff', body: ['person_id' => 'p-vac', 'kind' => 'permanent', 'tenant_id' => 't2']));
    }

    public function testLesDroitsSontRespectes(): void
    {
        $row = $this->staff('p-prof');

        self::assertSame(403, $this->code('POST', '/staff', 'boss', body: ['person_id' => 'p-vac', 'kind' => 'permanent']));
        self::assertSame(403, $this->code('PATCH', '/staff/' . $row['id'], 'boss', body: ['grade' => 'x']));
        self::assertSame(200, $this->code('GET', '/staff/' . $row['id'], 'boss'));
    }

    public function testUnEnseignantLitSaFicheMaisPasCelleDesAutres(): void
    {
        $mine = $this->staff('p-prof');
        $other = $this->staff('p-prof2');

        self::assertSame(200, $this->code('GET', '/staff/' . $mine['id'], 'prof'));
        self::assertSame(404, $this->code('GET', '/staff/' . $other['id'], 'prof'));
        self::assertSame([$mine['id']], array_column($this->api('GET', '/staff', 'prof')[1]['data'], 'id'));
        self::assertSame(403, $this->code('PATCH', '/staff/' . $mine['id'], 'prof', body: ['grade' => 'Directeur']));
        self::assertSame(200, $this->code('GET', '/staff/' . $mine['id'] . '/assignments', 'prof'));
        self::assertSame(404, $this->code('GET', '/staff/' . $other['id'] . '/assignments', 'prof'));
    }

    public function testMiseAJourEtDepart(): void
    {
        $row = $this->staff('p-vac', 'vacataire');
        $out = $this->api('PATCH', '/staff/' . $row['id'], body: ['grade' => 'Chargé de cours', 'status' => 'left'])[1]['data'];

        self::assertSame('Chargé de cours', $out['grade']);
        self::assertSame('left', $out['status']);
        self::assertSame('2026-10-08', $out['left_on']);
        self::assertSame(409, $this->code('POST', '/staff/' . $row['id'] . '/assignments', body: ['session_kind' => 'td']));

        $back = $this->api('PATCH', '/staff/' . $row['id'], body: ['status' => 'active'])[1]['data'];
        self::assertNull($back['left_on']);
    }

    public function testQualifications(): void
    {
        $row = $this->staff('p-prof');
        $path = '/staff/' . $row['id'] . '/qualifications';
        [$response, $body] = $this->api('POST', $path, body: ['title' => 'Master en mathématiques', 'institution' => 'Université de Yaoundé I', 'obtained_on' => '2018-07-10']);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $this->api('GET', $path)[1]['data']);
        self::assertSame(422, $this->code('POST', $path, body: ['institution' => 'x']));
        self::assertSame(204, $this->code('DELETE', $path . '/' . $body['data']['id']));
        self::assertSame([], $this->api('GET', $path)[1]['data']);
        self::assertSame(404, $this->code('DELETE', $path . '/' . $body['data']['id']));
    }

    public function testAffectationsEtChargeHoraire(): void
    {
        $row = $this->staff('p-prof');
        $path = '/staff/' . $row['id'] . '/assignments';
        [$response, $body] = $this->api('POST', $path, body: [
            'structure_id' => 'classe-3e', 'subject_id' => 'maths', 'subject_label' => 'Mathématiques', 'session_kind' => 'cm', 'planned_minutes' => 3600,
            'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30',
        ]);

        self::assertSame(201, $response->getStatusCode(), json_encode($body));
        self::assertSame(3600, $body['data']['planned_minutes']);
        self::assertSame(0, $body['data']['validated_minutes']);
        self::assertSame(422, $this->code('POST', $path, body: ['session_kind' => 'td', 'starts_on' => '2026-09-01', 'ends_on' => '2026-08-01']));

        $assignmentId = (string) $body['data']['id'];
        self::assertSame(200, $this->code('PATCH', $path . '/' . $assignmentId, body: ['planned_minutes' => 4200]));
        self::assertSame(4200, $this->api('GET', $path)[1]['data'][0]['planned_minutes']);
        self::assertSame(204, $this->code('DELETE', $path . '/' . $assignmentId));
        self::assertSame([], $this->api('GET', $path)[1]['data']);
    }

    public function testUneAffectationAvecDesHeuresNeSupprimePas(): void
    {
        $row = $this->staff('p-prof');
        $assignment = $this->api('POST', '/staff/' . $row['id'] . '/assignments', body: ['session_kind' => 'td', 'planned_minutes' => 600])[1]['data'];
        $this->declaredHour($row['id'], 'prof', '2026-10-05', 60, 'td', ['assignment_id' => $assignment['id']]);

        self::assertSame(409, $this->code('DELETE', '/staff/' . $row['id'] . '/assignments/' . $assignment['id']));
        self::assertSame(200, $this->code('PATCH', '/staff/' . $row['id'] . '/assignments/' . $assignment['id'], body: ['status' => 'ended']));
    }

    public function testAnnuaireDuPersonnel(): void
    {
        $this->staff('p-prof', 'permanent', ['grade' => 'PLEG']);
        $vac = $this->staff('p-vac', 'vacataire');
        $this->api('PATCH', '/staff/' . $vac['id'], body: ['status' => 'suspended']);
        $this->staff('p-prof2', 'invite');
        $directory = $this->kernel->container()->get(StaffDirectory::class);

        $info = $directory->find('t1', 'p-prof');
        self::assertInstanceOf(StaffInfo::class, $info);
        self::assertSame(['permanent', 'PLEG', 'active', 't1'], [$info->kind, $info->grade, $info->status, $info->tenantId]);
        self::assertNull($directory->find('t2', 'p-prof'));
        self::assertNull($directory->find('t1', 'inconnue'));
        self::assertSame(['p-prof', 'p-prof2'], $directory->teacherIdsIn('t1', 'e1'));
        self::assertSame([], $directory->teacherIdsIn('t1', 'e2'));
        self::assertSame([], $directory->teacherIdsIn('t2', 'e1'));
    }

    public function testUnEnseignantAffecteAUnAutreEtablissementDevientVisibleDansCelui_Ci(): void
    {
        $row = $this->staff('p-prof');
        $this->access->grant('admin', 'e2');
        $directory = $this->kernel->container()->get(StaffDirectory::class);

        self::assertSame(404, $this->code('GET', '/staff/' . $row['id'], establishment: 'e2'));
        self::assertSame([], $directory->teacherIdsIn('t1', 'e2'));

        self::assertSame(201, $this->code('POST', '/staff/' . $row['id'] . '/assignments', establishment: 'e2', body: ['session_kind' => 'td']));
        self::assertSame(200, $this->code('GET', '/staff/' . $row['id'], establishment: 'e2'));
        self::assertSame(['p-prof'], $directory->teacherIdsIn('t1', 'e2'));
        // les affectations de e1 restent invisibles depuis e2
        self::assertCount(1, $this->api('GET', '/staff/' . $row['id'] . '/assignments', establishment: 'e2')[1]['data']);
        self::assertSame([], $this->api('GET', '/staff/' . $row['id'] . '/assignments')[1]['data']);
    }
}
