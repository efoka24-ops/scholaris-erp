<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Tests\Support\Attendance\AttendanceTestCase;
use Tests\Support\Exports\FetchesCsv;
use Tests\Support\Exports\ReadsXlsx;

/** Exports CSV / Excel des séances, présences, justificatifs, incidents et sanctions : filtres, droits, isolation. */
final class ExportTest extends AttendanceTestCase
{
    use FetchesCsv;
    use ReadsXlsx;

    protected function setUp(): void
    {
        $this->bootAttendance();
        [$response] = $this->api('POST', '/attendance-sessions', 'teacher', [
            'client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => '2026-10-08',
            'records' => [['person_id' => 's1', 'status' => 'P'], ['person_id' => 's2', 'status' => 'A'], ['person_id' => 's3', 'status' => 'P']],
            'validate' => true,
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /** @return array<string, string> */
    private function as(string $user, string $tenant = 't1', string $establishment = 'e1'): array
    {
        return ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
    }

    public function testExportDesSeancesEtDesAbsences(): void
    {
        $sessions = $this->csvRows('/attendance-sessions/export?filter[date]=2026-10-08', $this->as('reader'));
        self::assertCount(2, $sessions);
        self::assertContains('Mathématiques', $sessions[1]);
        self::assertCount(1, $this->csvRows('/attendance-sessions/export?filter[date]=2026-10-09', $this->as('reader')));

        $absences = $this->csvRows('/attendance-records/export?filter[status]=A', $this->as('reader'));
        self::assertCount(2, $absences);
        self::assertContains('s2', $absences[1]);
        self::assertCount(4, $this->csvRows('/attendance-records/export', $this->as('reader')));

        self::assertSame(422, $this->fetch('/attendance-sessions/export?filter[date]=hier', $this->as('reader'))->getStatusCode());
        self::assertSame(422, $this->fetch('/attendance-records/export?filter[x]=1', $this->as('reader'))->getStatusCode());
    }

    public function testDroitsDeLectureDeLaListe(): void
    {
        self::assertSame(403, $this->fetch('/attendance-sessions/export', $this->as('nobody'))->getStatusCode());
        self::assertSame(403, $this->fetch('/attendance-sessions/export', $this->as('parent1'))->getStatusCode());
        self::assertSame(403, $this->fetch('/attendance-records/export', $this->as('parent1'))->getStatusCode(), 'Un parent doit préciser son enfant.');

        $own = $this->csvRows('/attendance-records/export?filter[person_id]=s1', $this->as('parent1'));
        self::assertCount(2, $own);
        self::assertSame(403, $this->fetch('/attendance-records/export?filter[person_id]=s2', $this->as('parent1'))->getStatusCode());
    }

    private function seedDiscipline(): void
    {
        $day = '2026-10-08';
        foreach (['s2', 's3'] as $person) {
            [$response] = $this->api('POST', '/absence-justifications', 'vs', ['person_id' => $person, 'absence_from' => $day, 'absence_to' => $day, 'reason' => 'Certificat médical', 'document_ref' => 'DOC-77']);
            self::assertSame(201, $response->getStatusCode());
        }
        [$response, $json] = $this->api('POST', '/incidents', 'surveillant', ['person_id' => 's3', 'occurred_on' => $day, 'category' => 'violence', 'description' => 'Bagarre dans la cour']);
        self::assertSame(201, $response->getStatusCode());
        [$response] = $this->api('POST', '/incidents', 'surveillant', ['person_id' => 's1', 'occurred_on' => $day, 'category' => 'retard', 'description' => 'Retard répété']);
        self::assertSame(201, $response->getStatusCode());
        [$response] = $this->api('POST', '/sanctions', 'surveillant', ['person_id' => 's3', 'kind' => 'blame', 'reason' => 'Violence', 'incident_id' => $json['data']['id']]);
        self::assertSame(201, $response->getStatusCode());
    }

    public function testExportDesJustificatifs(): void
    {
        $this->seedDiscipline();
        $all = $this->csvRows('/absence-justifications/export', $this->as('vs'));
        self::assertCount(3, $all);
        self::assertSame(['Identifiant', 'Personne', 'Absence du'], array_slice($all[0], 0, 3));
        self::assertContains('Certificat médical', $all[1]);

        self::assertCount(2, $this->csvRows('/absence-justifications/export?filter[person_id]=s2', $this->as('vs')));
        self::assertCount(1, $this->csvRows('/absence-justifications/export?filter[status]=accepted', $this->as('vs')));
        self::assertSame(422, $this->fetch('/absence-justifications/export?filter[x]=1', $this->as('vs'))->getStatusCode());
        // Un parent ne voit que ses enfants et doit préciser lequel.
        self::assertSame(403, $this->fetch('/absence-justifications/export', $this->as('parent2'))->getStatusCode());
        self::assertCount(2, $this->csvRows('/absence-justifications/export?filter[person_id]=s2', $this->as('parent2')));
        self::assertSame(403, $this->fetch('/absence-justifications/export?filter[person_id]=s3', $this->as('parent2'))->getStatusCode());
        self::assertSame(403, $this->fetch('/absence-justifications/export', $this->as('nobody'))->getStatusCode());
    }

    public function testExportDesIncidentsEtDesSanctions(): void
    {
        $this->seedDiscipline();
        $incidents = $this->csvRows('/incidents/export', $this->as('surveillant'));
        self::assertCount(3, $incidents);
        self::assertSame(['Identifiant', 'Personne', 'Date', 'Catégorie'], array_slice($incidents[0], 0, 4));
        self::assertCount(2, $this->csvRows('/incidents/export?filter[category]=violence', $this->as('surveillant')));
        self::assertCount(2, $this->csvRows('/incidents/export?filter[person_id]=s1', $this->as('censeur')));

        $sanctions = $this->csvRows('/sanctions/export', $this->as('censeur'));
        self::assertCount(2, $sanctions);
        self::assertContains('blame', $sanctions[1]);
        self::assertCount(1, $this->csvRows('/sanctions/export?filter[status]=approved', $this->as('censeur')));
        self::assertSame(422, $this->fetch('/sanctions/export?filter[x]=1', $this->as('censeur'))->getStatusCode());

        foreach (['/incidents/export', '/sanctions/export'] as $path) {
            self::assertSame(403, $this->fetch($path, $this->as('reader'))->getStatusCode(), $path);
            self::assertSame(403, $this->fetch($path, $this->as('parent1'))->getStatusCode(), $path);
        }
    }

    public function testFormatExcelSurTousLesExportsDuModule(): void
    {
        $this->seedDiscipline();
        $counts = [
            '/attendance-sessions/export' => ['reader', 2], '/attendance-records/export' => ['reader', 4], '/absence-justifications/export' => ['vs', 3],
            '/incidents/export' => ['surveillant', 3], '/sanctions/export' => ['censeur', 2],
        ];
        foreach ($counts as $path => [$user, $count]) {
            self::assertCount($count, $this->xlsxRows($path . '?format=xlsx', $this->as($user)), $path);
        }
        self::assertSame(422, $this->fetch('/incidents/export?format=ods', $this->as('surveillant'))->getStatusCode());
    }

    public function testIsolationEntreEtablissementsEtEntreClients(): void
    {
        $this->seedDiscipline();
        $users = ['/attendance-sessions/export' => 'reader', '/attendance-records/export' => 'reader', '/absence-justifications/export' => 'vs', '/incidents/export' => 'surveillant', '/sanctions/export' => 'censeur'];
        foreach ($users as $path => $user) {
            self::assertGreaterThan(1, count($this->csvRows($path, $this->as($user))), $path . ' : données présentes');
            self::assertCount(1, $this->csvRows($path, $this->as($user, 't1', 'e2')), $path . ' : autre établissement');
            self::assertCount(1, $this->csvRows($path, $this->as($user, 't2', 'e1')), $path . ' : autre client');
        }
    }
}
