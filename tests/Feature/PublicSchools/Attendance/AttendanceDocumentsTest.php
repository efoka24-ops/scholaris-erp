<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Attendance;

use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/** T637 : PDF DOC-06 (cahier d'appel mensuel) et DOC-22 (synthèse de présence des enseignants) émis par le module Documents du socle. */
final class AttendanceDocumentsTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
    }

    public function testLeDirecteurEmetLeCahierDAppelMensuel(): void
    {
        $this->putAttendance('teacher', 'c1', self::DAY, 'morning', [['person_id' => 's2', 'status' => 'A']]);
        [$response, $json] = $this->api('POST', '/public/classes/c1/attendance-register/document', 'director', ['month' => '2026-10']);
        [$teacher] = $this->api('POST', '/public/classes/c1/attendance-register/document', 'teacher', ['month' => '2026-10']);
        [$bad] = $this->api('POST', '/public/classes/c1/attendance-register/document', 'director', ['month' => 'octobre']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotEmpty($json['data']['number']);
        self::assertSame(403, $teacher->getStatusCode());
        self::assertSame(422, $bad->getStatusCode());
        $content = (string) $this->rows("SELECT content FROM documents WHERE type_code = 'pub_doc06_register'")[0]['content'];
        self::assertStringContainsString('Cahier d', $content);
        self::assertStringContainsString('Nadia Foka', $content);
        self::assertStringContainsString('Aimé Mballa', $content, 'Titulaire de la classe.');
    }

    public function testLeDirecteurEmetLaSyntheseDePresenceDesEnseignants(): void
    {
        $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY, 'director', ['presence' => 'present']);
        [$response, $json] = $this->api('POST', '/public/teacher-presence/summary/document', 'director', ['month' => '2026-10', 'locale' => 'en']);
        [$inspector] = $this->api('POST', '/public/teacher-presence/summary/document', 'inspector', ['month' => '2026-10']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotEmpty($json['data']['number']);
        self::assertSame(403, $inspector->getStatusCode());
        $content = (string) $this->rows("SELECT content FROM documents WHERE type_code = 'pub_doc22_presence'")[0]['content'];
        self::assertStringContainsString('Monthly teacher attendance summary', $content);
        self::assertStringContainsString('Aimé Mballa', $content);
    }
}
