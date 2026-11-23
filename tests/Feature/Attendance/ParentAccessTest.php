<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Tests\Support\Attendance\AttendanceTestCase;

/** Un parent lit l'assiduité de ses enfants (consentement parent_access pour un majeur) ; un apprenant lit la sienne. */
final class ParentAccessTest extends AttendanceTestCase
{
    protected function setUp(): void
    {
        $this->bootAttendance();
        $this->api('POST', '/attendance-sessions', 'teacher', ['client_uuid' => self::ulid(), 'slot_id' => 'slot1', 'date' => self::DAY, 'records' => $this->fullRecords(), 'validate' => true]);
    }

    private function code(string $path, string $user): int
    {
        return $this->api('GET', $path, $user)[0]->getStatusCode();
    }

    public function testUnParentLitSesEnfantsEtRienDAutre(): void
    {
        self::assertSame(200, $this->code('/attendance/students/s1/summary', 'parent1'));
        self::assertSame(200, $this->code('/attendance/students/s2/summary', 'parent2'));
        self::assertSame(404, $this->code('/attendance/students/s2/summary', 'parent1'));
        self::assertSame(404, $this->code('/attendance/students/s3/summary', 'parent1'));
        self::assertSame(404, $this->code('/attendance/students/s1/summary', 'nobody'));
    }

    public function testUnApprenantLitLaSienne(): void
    {
        self::assertSame(200, $this->code('/attendance/students/s1/summary', 'student1'));
        self::assertSame(404, $this->code('/attendance/students/s2/summary', 'student1'));
    }

    public function testLesLignesNeSontLisiblesQuePourSesEnfants(): void
    {
        [$ok, $json] = $this->api('GET', '/attendance-records?filter[person_id]=s2', 'parent2');

        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(['A'], array_column($json['data'], 'status'));
        self::assertSame(403, $this->code('/attendance-records', 'parent2'));
        self::assertSame(403, $this->code('/attendance-records?filter[person_id]=s1', 'parent2'));
        self::assertSame(403, $this->code('/attendance-records?filter[person_id]=s1', 'nobody'));
    }

    public function testPourUnMajeurLeConsentementParentAccessEstExige(): void
    {
        $this->connections()->pdo()->prepare('INSERT INTO people (id, tenant_id, first_name, last_name, created_at, birth_date) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['s1', 't1', 'Yann', 'Foka', '2026-01-01T00:00:00+01:00', '2000-03-01']);

        self::assertSame(404, $this->code('/attendance/students/s1/summary', 'parent1'), 'Majeur sans consentement.');
        self::assertSame(200, $this->code('/attendance/students/s1/summary', 'student1'), 'L\'apprenant majeur lit la sienne.');

        $this->consents->grant('t1', 's1', ConsentChecker::PARENT_ACCESS);
        self::assertSame(200, $this->code('/attendance/students/s1/summary', 'parent1'));
    }

    public function testLaVieScolaireLitToutDansSonEtablissement(): void
    {
        self::assertSame(200, $this->code('/attendance/students/s3/summary', 'reader'));
        self::assertSame(200, $this->code('/attendance-records', 'reader'));
        self::assertSame(403, $this->code('/attendance-records', 'nobody'));
    }
}
