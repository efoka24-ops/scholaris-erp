<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Tests\Support\Attendance\AttendanceTestCase;

/** T111 : justificatifs d'absence, dépôt puis validation ou refus par la vie scolaire ; une absence justifiée ne compte pas dans le non justifié. */
final class JustificationTest extends AttendanceTestCase
{
    protected function setUp(): void
    {
        $this->bootAttendance();
        [$response] = $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY, 'records' => $this->fullRecords(), 'validate' => true]);
        self::assertSame(201, $response->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function submit(string $user = 'vs', string $person = 's2'): array
    {
        [$response, $json] = $this->api('POST', '/absence-justifications', $user, ['person_id' => $person, 'absence_from' => self::DAY, 'absence_to' => self::DAY, 'reason' => 'Certificat médical', 'document_ref' => 'DOC-77']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    public function testLeDepotEstEnAttenteEtNeJustifieRien(): void
    {
        $justification = $this->submit();

        self::assertSame('pending', $justification['status']);
        self::assertSame('DOC-77', $justification['document_ref']);
        [, $summary] = $this->api('GET', '/attendance/students/s2/summary', 'reader');
        self::assertSame(1, $summary['data']['unjustified_absences']);
    }

    public function testLaValidationJustifieLesAbsencesDeLaPeriode(): void
    {
        $justification = $this->submit();

        [$response, $json] = $this->api('POST', '/absence-justifications/' . $justification['id'] . '/accept', 'vs', ['note' => 'Document conforme']);
        [, $summary] = $this->api('GET', '/attendance/students/s2/summary', 'reader');
        [, $records] = $this->api('GET', '/attendance-records?filter[person_id]=s2', 'reader');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['accepted', 1, 'vs'], [$json['data']['status'], $json['data']['absences_justified'], $json['data']['decided_by']]);
        self::assertSame([1, 0, 1], [$summary['data']['absences'], $summary['data']['unjustified_absences'], $summary['data']['justified_absences']]);
        self::assertSame(1, $records['data'][0]['justified']);
        self::assertSame($justification['id'], $records['data'][0]['justification_id']);
    }

    public function testLeRefusLaisseLAbsenceNonJustifiee(): void
    {
        $justification = $this->submit();

        [$response, $json] = $this->api('POST', '/absence-justifications/' . $justification['id'] . '/reject', 'vs', ['note' => 'Document illisible']);
        [, $summary] = $this->api('GET', '/attendance/students/s2/summary', 'reader');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['rejected', 'Document illisible'], [$json['data']['status'], $json['data']['decision_note']]);
        self::assertSame(1, $summary['data']['unjustified_absences']);
    }

    public function testUneDecisionNeSeRepetePas(): void
    {
        $justification = $this->submit();
        $this->api('POST', '/absence-justifications/' . $justification['id'] . '/reject', 'vs');

        [$again] = $this->api('POST', '/absence-justifications/' . $justification['id'] . '/accept', 'vs');

        self::assertSame(409, $again->getStatusCode());
    }

    public function testSeuleLaVieScolaireDecide(): void
    {
        $justification = $this->submit();

        foreach (['reader', 'teacher', 'parent2', 'nobody'] as $user) {
            [$response] = $this->api('POST', '/absence-justifications/' . $justification['id'] . '/accept', $user);
            self::assertSame(403, $response->getStatusCode(), $user);
        }
    }

    public function testLeParentDeposeUnJustificatifPourSonEnfantSeulement(): void
    {
        $own = $this->submit('parent2', 's2');
        [$other] = $this->api('POST', '/absence-justifications', 'parent2', ['person_id' => 's3', 'absence_from' => self::DAY, 'absence_to' => self::DAY, 'reason' => 'Maladie']);
        [$stranger] = $this->api('POST', '/absence-justifications', 'nobody', ['person_id' => 's2', 'absence_from' => self::DAY, 'absence_to' => self::DAY, 'reason' => 'Maladie']);
        [$show] = $this->api('GET', '/absence-justifications/' . $own['id'], 'parent2');
        [$hidden] = $this->api('GET', '/absence-justifications/' . $own['id'], 'parent1');
        [$list] = $this->api('GET', '/absence-justifications?filter[person_id]=s2', 'parent2');
        [$forbiddenList] = $this->api('GET', '/absence-justifications', 'parent2');

        self::assertSame('pending', $own['status']);
        self::assertSame(403, $other->getStatusCode());
        self::assertSame(403, $stranger->getStatusCode());
        self::assertSame(200, $show->getStatusCode());
        self::assertSame(404, $hidden->getStatusCode());
        self::assertSame(200, $list->getStatusCode());
        self::assertSame(403, $forbiddenList->getStatusCode());
    }

    public function testLaPeriodeEtLApprenantSontControles(): void
    {
        [$reversed, $json] = $this->api('POST', '/absence-justifications', 'vs', ['person_id' => 's2', 'absence_from' => '2026-10-08', 'absence_to' => '2026-10-01', 'reason' => 'x']);
        [$unknown] = $this->api('POST', '/absence-justifications', 'vs', ['person_id' => 'inconnu', 'absence_from' => self::DAY, 'absence_to' => self::DAY, 'reason' => 'x']);
        [$missing, $two] = $this->api('POST', '/absence-justifications', 'vs', ['person_id' => 's2']);

        self::assertSame(422, $reversed->getStatusCode());
        self::assertArrayHasKey('absence_to', $json['errors']);
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame(422, $missing->getStatusCode());
        self::assertArrayHasKey('reason', $two['errors']);
    }
}
