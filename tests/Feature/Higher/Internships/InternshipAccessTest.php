<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Internships;

use Tests\Support\Higher\Internships\InternshipsTestCase;

/** Périmètre (RG-ORG-2), isolation entre clients et « Mon stage » : l'étudiant ne voit que son stage. */
final class InternshipAccessTest extends InternshipsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInternships();
    }

    public function testHorsPerimetre404EnLectureEt403EnEcriture(): void
    {
        $id = (string) $this->convention()['id'];

        self::assertSame(404, $this->call('GET', '/higher/internships/' . $id, null, self::OTHER_OFFICER)[0]);
        self::assertSame([], $this->ok('GET', '/higher/internships', null, self::OTHER_OFFICER));
        self::assertSame(403, $this->call('PATCH', '/higher/internships/' . $id, ['subject' => 'Piratage'], self::OTHER_OFFICER)[0]);
        self::assertContains('higher.scope.denied', array_column($this->audit->entries, 'action'));
        self::assertSame(403, $this->call('POST', '/higher/internships', ['enrolment_id' => 'enr-p2'], self::DIRECTOR)[0], 'Lecture seule.');
        self::assertCount(1, $this->ok('GET', '/higher/internships', null, self::DIRECTOR));
    }

    public function testUnAutreClientNeVoitRien(): void
    {
        $id = (string) $this->convention()['id'];
        $this->grantAll(self::TB, self::ADMIN_B, self::EB, ['higher.internship.manage', 'higher.defense.manage']);

        self::assertSame(404, $this->call('GET', '/higher/internships/' . $id, null, self::ADMIN_B, self::TB, self::EB)[0]);
        self::assertSame([], $this->ok('GET', '/higher/internships', null, self::ADMIN_B, self::TB, self::EB));
        self::assertSame(404, $this->call('POST', '/higher/internships/' . $id . '/cancel', ['reason' => 'x'], self::ADMIN_B, self::TB, self::EB)[0]);
    }

    public function testLEtudiantNeVoitQueSonStageEtDeposeSonMemoire(): void
    {
        $id = (string) $this->signed('p1')['id'];
        $this->convention('p2');

        $mine = $this->ok('GET', '/higher/students/me/internship', null, self::STUDENT);
        self::assertSame($id, $mine['internship']['id']);
        self::assertSame(['completion_certificate', 'thesis_deposited', 'financial_clearance'], $mine['readiness']['missing']);
        self::assertSame('p2', $this->ok('GET', '/higher/students/me/internship', null, self::OTHER_STUDENT)['internship']['person_id']);

        self::assertSame(403, $this->call('GET', '/higher/internships', null, self::STUDENT)[0]);
        self::assertSame(403, $this->call('GET', '/higher/internships/' . $id, null, self::OTHER_STUDENT)[0]);
        self::assertSame(409, $this->call('POST', '/higher/students/me/internship/thesis', ['title' => 'T', 'file_ref' => 'f.pdf'], self::OTHER_STUDENT)[0], 'Convention de Paul non signée.');

        $deposited = $this->ok('POST', '/higher/students/me/internship/thesis', ['title' => 'Tableaux de bord', 'file_ref' => 'memoires/marie.pdf'], self::STUDENT);
        self::assertNotNull($deposited['thesis_deposited_at']);
        self::assertSame(404, $this->call('GET', '/higher/students/me/internship', null, self::PRESIDENT)[0], 'Aucun stage.');
    }

    public function testLaNoteNEstVisibleDeLEtudiantQuApresLePv(): void
    {
        [, $defense] = $this->schedule($this->ready());
        $id = (string) $defense['data']['id'];
        $this->ok('POST', '/higher/defenses/' . $id . '/convene', null, self::OFFICER);
        $this->clock->freeze('2026-10-20');
        $this->ok('POST', '/higher/defenses/' . $id . '/grade', ['grade' => 12], self::OFFICER);

        self::assertNull($this->ok('GET', '/higher/students/me/internship', null, self::STUDENT)['defense']['grade']);
        $this->ok('POST', '/higher/defenses/' . $id . '/minutes', null, self::OFFICER);
        self::assertEquals(12, $this->ok('GET', '/higher/students/me/internship', null, self::STUDENT)['defense']['grade']);
        self::assertSame(403, $this->call('GET', '/higher/defenses/' . $id, null, self::OTHER_STUDENT)[0]);
        self::assertSame(200, $this->call('GET', '/higher/defenses/' . $id, null, self::PRESIDENT)[0], 'Membre du jury.');
    }
}
