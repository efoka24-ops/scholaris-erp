<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Census;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * T652, T653 : questionnaire statistique pré-rempli (DOC-23), contrôles de cohérence (TST-08), circuit inspection > département >
 * région (PP5) avec renvoi motivé, gel régional (RG-STA-1), consolidation par la planification, correction = version motivée,
 * saisie par l'agent de l'inspection (RG-STA-2), export au gabarit par défaut (Q3).
 */
final class CensusTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
        $this->permissions->grant('director', 'public_schools.census.write');
        $this->permissions->grant('inspector', 'public_schools.census.validate');
        $this->actor('dep', 'p-dep', ['public_schools.census.validate'], Scope::territory('dep-1', 'pub_departmental_delegate', 't1', 'e1'));
        $this->actor('reg', 'p-reg', ['public_schools.census.validate'], Scope::territory('reg-1', 'pub_regional_delegate', 't1', 'e1'));
        $this->actor('planner', 'p-planner', ['public_schools.census.validate'], new Scope(Scope::ESTABLISHMENT, 'e1', 'pub_planning'));
        $this->actor('clerk', 'p-clerk', ['public_schools.census.write'], Scope::territory('arr-1', 'pub_inspection_clerk', 't1', 'e1'));
    }

    /** @return array<string, mixed> */
    private function open(string $user = 'director'): array
    {
        [$response, $json] = $this->api('POST', '/public/census', $user, ['year_label' => '2026-2027', 'campaign' => 'start_of_year']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    private function infrastructure(int $roomsInUse): void
    {
        $pdo = $this->connections()->pdo();
        $pdo->prepare("INSERT INTO pub_infrastructure_years (id, tenant_id, establishment_id, year_label, version, state, desks_count, water_points, latrines_girls, latrines_boys, has_electricity, has_fence, has_canteen, has_library, created_at) VALUES ('01INFRAYEAR000000000000001', 't1', 'e1', '2026-2027', 1, 'closed', 40, 1, 2, 2, 0, 1, 0, 0, '2026-09-30T08:00:00+01:00')")->execute();
        for ($i = 1; $i <= $roomsInUse; ++$i) {
            $pdo->prepare("INSERT INTO pub_classrooms (id, tenant_id, establishment_id, infrastructure_year_id, label, material, room_state, in_use) VALUES (?, 't1', 'e1', '01INFRAYEAR000000000000001', ?, 'permanent', 'good', 1)")->execute([sprintf('01ROOM%020d', $i), 'Salle ' . $i]);
        }
    }

    public function testLeQuestionnaireEstPreRempliDepuisLesDonneesDeLEcole(): void
    {
        $this->infrastructure(2);
        $form = $this->open();

        self::assertSame('draft', $form['state']);
        self::assertSame(5, $form['payload']['sections']['enrolment']['values']['pupils.total']);
        self::assertSame(2, $form['payload']['sections']['enrolment']['values']['classes.count']);
        self::assertSame(3, $form['payload']['sections']['enrolment']['values']['teachers.count']);
        self::assertSame(2, $form['payload']['sections']['infrastructure']['values']['classrooms_in_use']);
        self::assertStringNotContainsString('Foka', json_encode($form, JSON_THROW_ON_ERROR), 'Aucune donnée nominative dans le questionnaire.');
        self::assertFalse($form['entered_by_proxy']);
    }

    public function testUnQuestionnaireIncoherentEstRenvoyeAvecLesControlesEnErreur(): void
    {
        $form = $this->open();
        [$missing, $missingJson] = $this->api('POST', '/public/census/' . $form['id'] . '/submit', 'director', []);
        self::assertSame(422, $missing->getStatusCode());
        self::assertContains('infrastructure.missing_year', $missingJson['errors']['checks']);

        $this->infrastructure(2);
        $this->connections()->pdo()->prepare('DELETE FROM pub_census_forms')->execute();
        $form = $this->open();
        [$edit] = $this->api('PATCH', '/public/census/' . $form['id'], 'director', ['values' => ['enrolment.pupils.total' => 9]]);
        [$response, $json] = $this->api('POST', '/public/census/' . $form['id'] . '/submit', 'director', []);

        self::assertSame(200, $edit->getStatusCode(), (string) $edit->getBody());
        self::assertSame(422, $response->getStatusCode(), 'TST-08');
        self::assertContains('enrolment.age_total_mismatch', $json['errors']['checks']);
        self::assertSame('draft', $this->rows('SELECT form_state FROM pub_census_forms')[0]['form_state']);
    }

    public function testUnControleAJustifierPasseAvecSaJustification(): void
    {
        $this->infrastructure(2);
        $this->settings->values['public_schools.class_size.alert'] = 2;
        $form = $this->open();
        [$refused, $json] = $this->api('POST', '/public/census/' . $form['id'] . '/submit', 'director', []);
        [$accepted] = $this->api('POST', '/public/census/' . $form['id'] . '/submit', 'director', ['justifications' => ['enrolment.class_over_ceiling' => 'Classe de CP1 SYN dédoublée en mi-temps la semaine prochaine.', 'ratio.pupils_per_room_above_ceiling' => 'Idem.']]);

        self::assertSame(422, $refused->getStatusCode());
        self::assertContains('enrolment.class_over_ceiling', $json['errors']['checks']);
        self::assertSame(200, $accepted->getStatusCode(), (string) $accepted->getBody());
    }

    public function testCircuitCompletGelRegionalConsolidationEtCorrectionMotivee(): void
    {
        $this->infrastructure(2);
        $form = $this->open();
        $path = '/public/census/' . $form['id'];
        [$submitted, $submittedJson] = $this->api('POST', $path . '/submit', 'director', []);
        self::assertSame(200, $submitted->getStatusCode(), (string) $submitted->getBody());
        self::assertSame('district', $submittedJson['data']['current_step']);

        [$tooEarly] = $this->api('POST', $path . '/submit', 'dep', []);
        [, $pending] = $this->api('GET', '/public/census/pending', 'inspector');
        [$district] = $this->api('POST', $path . '/submit', 'inspector', []);
        [$department] = $this->api('POST', $path . '/submit', 'dep', []);
        [$region, $regionJson] = $this->api('POST', $path . '/submit', 'reg', []);
        [$frozenEdit] = $this->api('PATCH', $path, 'director', ['values' => ['enrolment.pupils.total' => 5]]);
        [$planning, $planningJson] = $this->api('POST', $path . '/submit', 'planner', []);
        [$correction, $correctionJson] = $this->api('POST', $path . '/corrections', 'director', ['reason' => 'Deux élèves SYN omis à la rentrée.']);
        [$noReason] = $this->api('POST', $path . '/corrections', 'director', []);

        self::assertSame(403, $tooEarly->getStatusCode(), 'Le département attend la validation de l\'inspection.');
        self::assertCount(1, $pending['data']);
        self::assertSame(200, $district->getStatusCode());
        self::assertSame(200, $department->getStatusCode());
        self::assertSame(200, $region->getStatusCode(), (string) $region->getBody());
        self::assertSame('frozen', $regionJson['data']['state'], 'RG-STA-1 : gel à la validation régionale.');
        self::assertSame(409, $frozenEdit->getStatusCode());
        self::assertSame('consolidated', $planningJson['data']['state']);
        self::assertSame(201, $correction->getStatusCode(), (string) $correction->getBody());
        self::assertSame(2, $correctionJson['data']['version']);
        self::assertSame('draft', $correctionJson['data']['state']);
        self::assertSame(422, $noReason->getStatusCode());
        self::assertCount(1, $this->rows("SELECT id FROM reporting_workflow_snapshots WHERE workflow_id = '" . $form['id'] . "'"));
    }

    public function testRenvoiMotiveAuDirecteurPuisNouvelleTransmission(): void
    {
        $this->infrastructure(2);
        $form = $this->open();
        $path = '/public/census/' . $form['id'];
        $this->api('POST', $path . '/submit', 'director', []);
        [$noReason] = $this->api('POST', $path . '/return', 'inspector', []);
        [$returned, $json] = $this->api('POST', $path . '/return', 'inspector', ['reason' => 'Effectifs de CE1 à vérifier.']);
        [$again] = $this->api('POST', $path . '/submit', 'director', []);

        self::assertSame(422, $noReason->getStatusCode());
        self::assertSame(200, $returned->getStatusCode(), (string) $returned->getBody());
        self::assertSame('returned', $json['data']['state']);
        self::assertSame('Effectifs de CE1 à vérifier.', $json['data']['return_reason']);
        self::assertCount(1, $this->messenger->of('pub.not11.supervisor'));
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
    }

    public function testLAgentDeLInspectionSaisitPourLEcoleEtLesAutresNeVoientRien(): void
    {
        $form = $this->open('clerk');
        [$teacher] = $this->api('GET', '/public/census/' . $form['id'], 'teacher');
        [$otherDistrict] = $this->api('GET', '/public/census/' . $form['id'], 'inspector2');
        [$otherSchool] = $this->api('GET', '/public/census/' . $form['id'], 'director', null, 't1', 'e2');
        [$export] = $this->api('GET', '/public/census/' . $form['id'] . '/export?format=csv', 'director');

        self::assertTrue($form['entered_by_proxy'], 'RG-STA-2 : saisie par procuration tracée.');
        self::assertSame(404, $teacher->getStatusCode());
        self::assertSame(404, $otherDistrict->getStatusCode());
        self::assertContains($otherSchool->getStatusCode(), [403, 404]);
        self::assertSame(200, $export->getStatusCode());
        self::assertStringContainsString('pupils.total', (string) $export->getBody());
    }
}
