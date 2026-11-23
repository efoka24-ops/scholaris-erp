<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Attendance;

use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * Isolation des ressources de T634 à T641 : un acteur d'un autre client, même autorisé sur l'établissement par erreur, ne lit
 * et n'écrit rien (appels, présences des enseignants, personnel, messages, annonces) et ne laisse aucune trace.
 */
final class PublicSchoolsIsolationTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
        $this->permissions->grant('stranger', 'public_schools.attendance.read', 'public_schools.attendance.write', 'public_schools.teacher_presence.read', 'public_schools.teacher_presence.write', 'public_schools.staff.read', 'public_schools.staff.write', 'public_schools.messages.send', 'public_schools.messages.announce_territory');
        $this->access->grant('stranger', 'e1');
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']]);
        $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY, 'director', ['presence' => 'present']);
        $this->api('POST', '/public/messages', 'director', ['audience' => 'school', 'template_key' => 'pub.class_notice', 'variables' => ['details' => 'Info']]);
    }

    public function testUnAutreClientNeLitNiNEcritAucuneRessource(): void
    {
        $calls = [
            ['GET', '/public/classes/c1/attendance/' . self::DAY, null],
            ['GET', '/public/classes/c1/attendance-register?month=2026-10', null],
            ['PUT', '/public/classes/c1/attendance/' . self::DAY, ['day_part' => 'afternoon', 'client_uuid' => self::ulid()]],
            ['GET', '/public/teacher-presence?date=' . self::DAY, null],
            ['PUT', '/public/staff/p-teacher/presence/' . self::DAY, ['presence' => 'absent', 'reason' => 'X']],
            ['GET', '/public/uncovered-classes?date=' . self::DAY, null],
            ['GET', '/public/attendance-alerts', null],
            ['GET', '/public/staff', null],
            ['GET', '/public/staff/needs', null],
            ['PUT', '/public/staff/p-teacher/profile', ['public_status' => 'volunteer']],
            ['POST', '/public/staff/p-teacher/moves', ['to_establishment_id' => 'e2', 'effective_on' => '2026-11-02']],
            ['GET', '/public/messages', null],
            ['POST', '/public/messages', ['audience' => 'school', 'template_key' => 'pub.class_notice', 'variables' => ['details' => 'Intrusion']]],
            ['POST', '/public/announcements', ['territory_id' => 'arr-1', 'title' => 'Intrusion', 'body' => 'Texte']],
        ];
        $sentBefore = count($this->messenger->sent);

        foreach ($calls as [$method, $path, $body]) {
            [$response, $json] = $this->api($method, $path, 'stranger', $body, 't2', 'e1');
            self::assertContains($response->getStatusCode(), [401, 403, 404], $method . ' ' . $path . ' : ' . $response->getStatusCode());
            self::assertArrayNotHasKey('data', $json, $method . ' ' . $path);
        }

        self::assertSame(1, count($this->rows('SELECT id FROM attendance_sessions')));
        self::assertSame(1, count($this->rows('SELECT id FROM pub_teacher_presence')));
        self::assertSame('present', $this->rows('SELECT presence FROM pub_teacher_presence')[0]['presence']);
        self::assertSame([], $this->rows('SELECT id FROM pub_staff_profiles'));
        self::assertSame([], $this->rows('SELECT id FROM announcements'));
        self::assertCount(1, $this->rows('SELECT id FROM pub_message_batches'));
        self::assertCount($sentBefore, $this->messenger->sent);
    }
}
