<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Attendance;

use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * T636 : présence des enseignants (EX-PRE-03, EX-PRE-04), contrôle de l'inspection, « classe sans enseignant » (RG-PRE-2, NOT-13)
 * et synthèse mensuelle (DOC-22).
 */
final class TeacherPresenceTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function declare(string $user, string $person, string $date, array $body): array
    {
        return $this->api('PUT', '/public/staff/' . $person . '/presence/' . $date, $user, $body);
    }

    public function testLeDirecteurDeclareLArriveeEtLeDepartDUnEnseignant(): void
    {
        [$response, $json] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present', 'arrived_at' => '07:25', 'left_at' => '15:30']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('present', $json['data']['presence']);
        self::assertSame('07:25', $json['data']['arrived_at']);
        self::assertSame('st-teacher', $json['data']['staff_id']);
        self::assertSame([], $json['data']['uncovered_class_ids']);
        self::assertCount(1, $this->rows("SELECT action FROM audit_log WHERE action = 'public_schools.teacher_presence.declared'"));
    }

    public function testUnEnseignantAbsentSansRemplacantLaisseSaClasseSansEnseignantEtLeDirecteurEstPrevenu(): void
    {
        [$response, $json] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'absent', 'reason' => 'Maladie déclarée']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['c1'], $json['data']['uncovered_class_ids']);
        self::assertSame([['structure_id' => 'c1', 'day_on' => self::DAY]], $this->rows('SELECT structure_id, day_on FROM pub_uncovered_classes'));
        $notices = $this->messenger->of('pub.not13.director');
        self::assertCount(1, $notices, 'NOT-13 : le directeur est prévenu.');
        self::assertSame('p-director', $notices[0]['person']);
        self::assertSame('CP1', $notices[0]['variables']['class_name']);
        $alerts = $this->rows("SELECT level FROM pub_attendance_alerts WHERE kind = 'uncovered_class' ORDER BY level");
        self::assertSame(['director', 'inspection'], array_column($alerts, 'level'), 'L\'indicateur remonte aussi au fil de l\'inspection.');

        // Une seconde déclaration identique ne renvoie ni message ni ligne en double.
        $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'absent', 'reason' => 'Maladie déclarée']);
        self::assertCount(1, $this->messenger->of('pub.not13.director'));
        self::assertCount(1, $this->rows('SELECT id FROM pub_uncovered_classes'));
    }

    public function testUnRemplacantDeclareEvitePasLaClasseSansEnseignant(): void
    {
        [$response, $json] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'absent', 'reason' => 'Formation', 'replacement_person_id' => 'p-sub']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $json['data']['uncovered_class_ids']);
        self::assertSame('st-sub', $json['data']['replacement_staff_id']);
        self::assertSame([], $this->rows('SELECT id FROM pub_uncovered_classes'));
        self::assertSame([], $this->messenger->of('pub.not13.director'));
    }

    public function testLeDirecteurCorrigeSaDeclarationEtLaClasseRedevientCouverte(): void
    {
        $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'absent', 'reason' => 'Erreur de saisie']);

        [$response, $json] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present', 'arrived_at' => '08:00']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $json['data']['uncovered_class_ids']);
        self::assertSame([], $this->rows('SELECT id FROM pub_uncovered_classes'));
        self::assertCount(1, $this->rows('SELECT id FROM pub_teacher_presence'), 'Une seule ligne par enseignant et par jour.');
    }

    public function testUnePermissionExigeUnePieceEtUnMotif(): void
    {
        [$noProof, $noProofJson] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'permission', 'reason' => 'Mariage']);
        [$noReason, $noReasonJson] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'absent']);
        [$ok] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'permission', 'reason' => 'Mariage', 'proof_path' => 'justificatifs/SYN-0001.pdf']);

        self::assertSame(422, $noProof->getStatusCode());
        self::assertArrayHasKey('proof_path', $noProofJson['errors']);
        self::assertSame(422, $noReason->getStatusCode());
        self::assertArrayHasKey('reason', $noReasonJson['errors']);
        self::assertSame(200, $ok->getStatusCode());
    }

    public function testLaPieceNEstPasExigeeSiLeParametreLeDesactive(): void
    {
        $this->settings->values['public_schools.teacher_presence.proof_required'] = false;

        [$response] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'permission', 'reason' => 'Mariage']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testLesEntreesInvalidesSontRefuseesParChamp(): void
    {
        [$type, $typeJson] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'vacances']);
        [$time, $timeJson] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present', 'arrived_at' => '25:99']);
        [$future, $futureJson] = $this->declare('director', 'p-teacher', '2026-10-09', ['presence' => 'present']);
        [$date, $dateJson] = $this->declare('director', 'p-teacher', '2026-02-30', ['presence' => 'present']);
        [$presentSub, $presentSubJson] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present', 'replacement_person_id' => 'p-sub']);

        self::assertSame(422, $type->getStatusCode());
        self::assertArrayHasKey('presence', $typeJson['errors']);
        self::assertSame(422, $time->getStatusCode());
        self::assertArrayHasKey('arrived_at', $timeJson['errors']);
        self::assertSame(422, $future->getStatusCode());
        self::assertArrayHasKey('date', $futureJson['errors']);
        self::assertSame(422, $date->getStatusCode());
        self::assertArrayHasKey('date', $dateJson['errors']);
        self::assertSame(422, $presentSub->getStatusCode());
        self::assertArrayHasKey('replacement_person_id', $presentSubJson['errors']);
        self::assertSame([], $this->rows('SELECT id FROM pub_teacher_presence'));
    }

    public function testSeulLeDirecteurDeclareEtSeulUnEnseignantDeLEcoleEstConnu(): void
    {
        [$teacher] = $this->declare('teacher', 'p-teacher', self::DAY, ['presence' => 'present']);
        [$inspector] = $this->declare('inspector', 'p-teacher', self::DAY, ['presence' => 'present']);
        [$stranger] = $this->declare('director', 'p-parent1', self::DAY, ['presence' => 'present']);
        [$otherSchool] = $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY, 'director', ['presence' => 'present'], 't1', 'e2');

        self::assertSame(403, $teacher->getStatusCode());
        self::assertSame(403, $inspector->getStatusCode());
        self::assertSame(404, $stranger->getStatusCode());
        self::assertContains($otherSchool->getStatusCode(), [403, 404]);
        self::assertSame([], $this->rows('SELECT id FROM pub_teacher_presence'));
    }

    public function testLIdentifiantClientNeServiraPasPourUneAutreDeclaration(): void
    {
        $uuid = self::ulid();
        $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present', 'client_uuid' => $uuid]);

        [$response] = $this->declare('director', 'p-teacher2', self::DAY, ['presence' => 'present', 'client_uuid' => $uuid]);
        [$same] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present', 'client_uuid' => $uuid]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(200, $same->getStatusCode());
        self::assertCount(1, $this->rows('SELECT id FROM pub_teacher_presence'));
    }

    public function testLInspectionControleUneDeclarationEtLaConsultationEstJournalisee(): void
    {
        $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present', 'arrived_at' => '07:30']);

        [$response, $json] = $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY . '/check', 'inspector', ['result' => 'discrepancy']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('discrepancy', $json['data']['check_result']);
        self::assertSame('inspector', $json['data']['checked_by']);
        self::assertCount(1, $this->journal->entries);
        self::assertSame(['tenant' => 't1', 'establishment' => 'e1', 'actor' => 'inspector', 'type' => 'teacher_presence', 'id' => 'p-teacher:' . self::DAY, 'purpose' => 'control'], $this->journal->entries[0]);
    }

    public function testUneDeclarationControleeEstVerrouilleeEtNeSeControlePasDeuxFois(): void
    {
        $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present']);
        $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY . '/check', 'inspector', ['result' => 'confirmed']);

        [$again] = $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY . '/check', 'inspector', ['result' => 'confirmed']);
        [$edit] = $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'absent', 'reason' => 'Retouche']);

        self::assertSame(409, $again->getStatusCode());
        self::assertSame(409, $edit->getStatusCode());
        self::assertSame('present', $this->rows('SELECT presence FROM pub_teacher_presence')[0]['presence']);
    }

    public function testLeControleExigeUnResultatValideUneDeclarationEtUnPerimetre(): void
    {
        [$none] = $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY . '/check', 'inspector', ['result' => 'confirmed']);
        $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present']);
        [$bad, $badJson] = $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY . '/check', 'inspector', ['result' => 'peut-etre']);
        [$otherTerritory] = $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY . '/check', 'inspector2', ['result' => 'confirmed']);
        [$director] = $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY . '/check', 'director', ['result' => 'confirmed']);
        [$outsider] = $this->api('PUT', '/public/staff/p-teacher/presence/' . self::DAY . '/check', 'outsider', ['result' => 'confirmed']);

        self::assertSame(404, $none->getStatusCode());
        self::assertSame(422, $bad->getStatusCode());
        self::assertArrayHasKey('result', $badJson['errors']);
        self::assertSame(403, $otherTerritory->getStatusCode(), 'Un autre arrondissement ne contrôle pas cette école.');
        self::assertSame(403, $director->getStatusCode(), 'Le contrôle est celui de l\'inspection.');
        self::assertSame(403, $outsider->getStatusCode());
        self::assertNotSame([], $this->rows("SELECT action FROM audit_log WHERE action = 'public_schools.scope.denied'"), 'Les refus hors périmètre sont journalisés.');
        self::assertCount(2, $this->journal->entries, 'Seules les tentatives de l\'inspection de ce territoire sont journalisées.');
    }

    public function testLEchantillonAleatoireNeContientQueDesPresencesNonControlees(): void
    {
        $this->settings->values['public_schools.inspection.sample_size'] = 2;
        $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present']);
        $this->declare('director', 'p-teacher2', self::DAY, ['presence' => 'present']);
        $this->declare('director', 'p-sub', self::DAY, ['presence' => 'present']);
        $this->api('PUT', '/public/staff/p-sub/presence/' . self::DAY . '/check', 'inspector', ['result' => 'confirmed']);
        $this->declare('director', 'p-teacher2', '2026-10-07', ['presence' => 'absent', 'reason' => 'Maladie']);

        [$response, $json] = $this->api('GET', '/public/teacher-presence/control-sample?date=' . self::DAY, 'inspector');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(2, $json['data']);
        $staff = array_column($json['data'], 'staff_id');
        sort($staff);
        self::assertSame(['st-teacher', 'st-teacher2'], $staff);
    }

    public function testLInspectionLitLesPresencesAvecUneFinaliteEtLeDirecteurSansFinalite(): void
    {
        $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'present']);

        [$noPurpose, $noPurposeJson] = $this->api('GET', '/public/teacher-presence?date=' . self::DAY, 'inspector');
        [$withPurpose, $json] = $this->api('GET', '/public/teacher-presence?date=' . self::DAY . '&purpose=control', 'inspector');
        [$director, $directorJson] = $this->api('GET', '/public/teacher-presence?date=' . self::DAY, 'director');
        [$teacher, $teacherJson] = $this->api('GET', '/public/teacher-presence?date=' . self::DAY, 'teacher');
        [$other] = $this->api('GET', '/public/teacher-presence?date=' . self::DAY . '&purpose=control', 'inspector2');
        [$badPurpose] = $this->api('GET', '/public/teacher-presence?date=' . self::DAY . '&purpose=curiosite', 'inspector');

        self::assertSame(422, $noPurpose->getStatusCode());
        self::assertArrayHasKey('purpose', $noPurposeJson['errors']);
        self::assertSame(200, $withPurpose->getStatusCode(), (string) $withPurpose->getBody());
        self::assertCount(1, $json['data']);
        self::assertCount(1, $this->journal->entries, 'La lecture est inscrite au journal des consultations nominatives.');
        self::assertEquals(['type' => 'teacher_presence_day', 'id' => self::DAY, 'purpose' => 'control', 'actor' => 'inspector'], array_intersect_key($this->journal->entries[0], array_flip(['type', 'id', 'purpose', 'actor'])));
        self::assertSame(200, $director->getStatusCode());
        self::assertCount(1, $directorJson['data']);
        self::assertSame(200, $teacher->getStatusCode());
        self::assertSame([], $teacherJson['data'], 'Un enseignant ne lit pas la présence de ses collègues.');
        self::assertSame(404, $other->getStatusCode(), 'Un autre arrondissement ne voit pas cette école.');
        self::assertSame(422, $badPurpose->getStatusCode());
    }

    public function testLaListeEstPagineeParCurseur(): void
    {
        foreach (['p-teacher', 'p-teacher2', 'p-sub'] as $person) {
            $this->declare('director', $person, self::DAY, ['presence' => 'present']);
        }

        [, $first] = $this->api('GET', '/public/teacher-presence?date=' . self::DAY . '&per_page=2', 'director');
        [, $second] = $this->api('GET', '/public/teacher-presence?date=' . self::DAY . '&per_page=2&cursor=' . $first['meta']['next_cursor'], 'director');
        [$tooMany] = $this->api('GET', '/public/teacher-presence?per_page=201', 'director');

        self::assertCount(2, $first['data']);
        self::assertNotNull($first['meta']['next_cursor']);
        self::assertCount(1, $second['data']);
        self::assertNull($second['meta']['next_cursor']);
        self::assertSame(422, $tooMany->getStatusCode());
    }

    public function testLaSyntheseMensuelleCompteLesJoursParEnseignant(): void
    {
        $this->declare('director', 'p-teacher', '2026-10-05', ['presence' => 'present']);
        $this->declare('director', 'p-teacher', '2026-10-06', ['presence' => 'absent', 'reason' => 'Maladie']);
        $this->declare('director', 'p-teacher', '2026-10-07', ['presence' => 'mission', 'reason' => 'Journée pédagogique', 'replacement_person_id' => 'p-sub']);
        $this->declare('director', 'p-teacher2', '2026-10-05', ['presence' => 'present']);
        $this->api('PUT', '/public/staff/p-teacher/presence/2026-10-05/check', 'inspector', ['result' => 'confirmed']);
        $this->declare('director', 'p-teacher', '2026-09-30', ['presence' => 'absent', 'reason' => 'Hors du mois']);

        [$response, $json] = $this->api('GET', '/public/teacher-presence/summary?month=2026-10', 'director');
        [$inspector] = $this->api('GET', '/public/teacher-presence/summary?month=2026-10&purpose=control', 'inspector');
        [$teacher] = $this->api('GET', '/public/teacher-presence/summary?month=2026-10', 'teacher');
        [$bad] = $this->api('GET', '/public/teacher-presence/summary?month=octobre', 'director');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $byStaff = array_column($json['data']['teachers'], null, 'staff_id');
        self::assertSame(['present' => 1, 'absent' => 1, 'permission' => 0, 'mission' => 1, 'checked' => 1, 'discrepancies' => 0], array_diff_key($byStaff['st-teacher'], ['staff_id' => 1]));
        self::assertSame(1, $byStaff['st-teacher2']['present']);
        self::assertSame(1, $json['data']['uncovered_days'], 'Le 6 octobre : absent sans remplaçant. Le 30 septembre est hors du mois.');
        self::assertSame(200, $inspector->getStatusCode());
        self::assertSame(403, $teacher->getStatusCode());
        self::assertSame(422, $bad->getStatusCode());
    }

    public function testLesClassesSansEnseignantSontListeesSansDonneeNominative(): void
    {
        $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'absent', 'reason' => 'Maladie']);

        [$response, $json] = $this->api('GET', '/public/uncovered-classes?date=' . self::DAY, 'inspector');
        [$teacher, $teacherJson] = $this->api('GET', '/public/uncovered-classes?date=' . self::DAY, 'teacher');
        [$teacher2, $teacher2Json] = $this->api('GET', '/public/uncovered-classes?date=' . self::DAY, 'teacher2');
        [$other] = $this->api('GET', '/public/uncovered-classes?date=' . self::DAY, 'inspector2');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['c1'], array_column($json['data'], 'structure_id'));
        self::assertSame(['c1'], array_column($teacherJson['data'], 'structure_id'));
        self::assertSame([], $teacher2Json['data'], 'L\'enseignant d\'une autre classe ne la voit pas.');
        self::assertSame(200, $teacher2->getStatusCode());
        self::assertSame(404, $other->getStatusCode());
        self::assertStringNotContainsString('p-teacher', (string) $response->getBody());
    }

    public function testLeFilDesAlertesDistingueLEcoleEtLInspection(): void
    {
        $this->declare('director', 'p-teacher', self::DAY, ['presence' => 'absent', 'reason' => 'Maladie']);

        [$director, $directorJson] = $this->api('GET', '/public/attendance-alerts', 'director');
        [$inspector, $inspectorJson] = $this->api('GET', '/public/attendance-alerts', 'inspector');
        [$teacher, $teacherJson] = $this->api('GET', '/public/attendance-alerts', 'teacher');

        self::assertSame(200, $director->getStatusCode());
        self::assertSame(['director', 'inspection'], array_column($directorJson['data'], 'level'));
        self::assertSame(['inspection'], array_column($inspectorJson['data'], 'level'));
        self::assertSame(200, $inspector->getStatusCode());
        self::assertSame([], $teacherJson['data']);
        self::assertSame(200, $teacher->getStatusCode());
    }
}
