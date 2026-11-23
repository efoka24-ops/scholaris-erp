<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Staff;

use Tests\Support\PublicSchools\PublicSchoolsTestCase;

/**
 * T639 : personnel de l'école publique (EX-PER-01 à EX-PER-04, RG-PER-2) : fiche de carrière, formations, mutations dans le
 * territoire (PP19) et besoins en enseignants.
 */
final class StaffTest extends PublicSchoolsTestCase
{
    protected function setUp(): void
    {
        $this->bootPublic();
    }

    /** @return array<string, mixed> */
    private function profile(string $status = 'civil_servant'): array
    {
        return [
            'public_status' => $status,
            'civil_service_number' => 'SYN-FP-000123',
            'professional_diploma' => 'CAPIEMP',
            'service_start_on' => '2015-09-01',
        ];
    }

    public function testLeDirecteurEnregistreEtRelitLaFicheDeCarriere(): void
    {
        [$saved, $json] = $this->api('PUT', '/public/staff/p-teacher/profile', 'director', $this->profile());
        [$read, $readJson] = $this->api('GET', '/public/staff/p-teacher/profile', 'director');

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame('civil_servant', $json['data']['public_status']);
        self::assertSame(200, $read->getStatusCode());
        self::assertSame('SYN-FP-000123', $readJson['data']['civil_service_number']);
        self::assertSame('CAPIEMP', $readJson['data']['professional_diploma']);
        self::assertSame('Instituteur', $readJson['data']['grade']);
        self::assertSame('st-teacher', $readJson['data']['staff_id']);

        // Mise à jour : une seule fiche par membre du personnel.
        $this->api('PUT', '/public/staff/p-teacher/profile', 'director', ['public_status' => 'contract', 'contract_number' => 'SYN-CT-77']);
        self::assertCount(1, $this->rows('SELECT id FROM pub_staff_profiles'));
        self::assertSame('contract', $this->rows('SELECT public_status FROM pub_staff_profiles')[0]['public_status']);
        self::assertCount(2, $this->rows("SELECT action FROM audit_log WHERE action = 'public_schools.staff.profile_saved'"));
    }

    public function testLaFicheExigeUnStatutPublicEtLeNumeroQuiLuiCorrespond(): void
    {
        [$status, $statusJson] = $this->api('PUT', '/public/staff/p-teacher/profile', 'director', ['public_status' => 'ministre']);
        [$matricule, $matriculeJson] = $this->api('PUT', '/public/staff/p-teacher/profile', 'director', ['public_status' => 'civil_servant']);
        [$contract, $contractJson] = $this->api('PUT', '/public/staff/p-teacher/profile', 'director', ['public_status' => 'contract']);
        [$date, $dateJson] = $this->api('PUT', '/public/staff/p-teacher/profile', 'director', ['public_status' => 'volunteer', 'service_start_on' => '2015-02-31']);
        [$parents] = $this->api('PUT', '/public/staff/p-sub/profile', 'director', ['public_status' => 'parents_teacher']);

        self::assertSame(422, $status->getStatusCode());
        self::assertArrayHasKey('public_status', $statusJson['errors']);
        self::assertSame(422, $matricule->getStatusCode());
        self::assertArrayHasKey('civil_service_number', $matriculeJson['errors']);
        self::assertSame(422, $contract->getStatusCode());
        self::assertArrayHasKey('contract_number', $contractJson['errors']);
        self::assertSame(422, $date->getStatusCode());
        self::assertArrayHasKey('service_start_on', $dateJson['errors']);
        self::assertSame(200, $parents->getStatusCode(), 'RG-PER-1 : un maître des parents est un statut ; sa rémunération relève de l\'APEE, pas de cette fiche.');
    }

    public function testLInspectionNeVoitQueLeStatutLeGradeEtLaFinaliteEstJournalisee(): void
    {
        $this->api('PUT', '/public/staff/p-teacher/profile', 'director', $this->profile());

        [$noPurpose, $noPurposeJson] = $this->api('GET', '/public/staff/p-teacher/profile', 'inspector');
        [$response, $json] = $this->api('GET', '/public/staff/p-teacher/profile?purpose=control', 'inspector');

        self::assertSame(422, $noPurpose->getStatusCode());
        self::assertArrayHasKey('purpose', $noPurposeJson['errors']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('civil_servant', $json['data']['public_status']);
        self::assertSame('Instituteur', $json['data']['grade']);
        foreach (['civil_service_number', 'contract_number', 'professional_diploma', 'service_start_on'] as $hidden) {
            self::assertArrayNotHasKey($hidden, $json['data'], 'RG-PER-2 : ' . $hidden . ' reste dans l\'école.');
        }
        self::assertStringNotContainsString('SYN-FP-000123', (string) $response->getBody());
        self::assertCount(1, $this->journal->entries);
        self::assertSame('control', $this->journal->entries[0]['purpose']);
        self::assertSame('inspector', $this->journal->entries[0]['actor']);
    }

    public function testSeuleLaDirectionEcritEtUnAutreArrondissementNeVoitRien(): void
    {
        [$teacher] = $this->api('PUT', '/public/staff/p-teacher/profile', 'teacher', $this->profile());
        [$inspector] = $this->api('PUT', '/public/staff/p-teacher/profile', 'inspector', $this->profile());
        [$otherDistrict] = $this->api('GET', '/public/staff/p-teacher/profile?purpose=control', 'inspector2');
        [$teacherRead] = $this->api('GET', '/public/staff/p-teacher/profile', 'teacher');
        [$unknown] = $this->api('GET', '/public/staff/p-parent1/profile', 'director');
        [$otherSchool] = $this->api('GET', '/public/staff/p-teacher/profile', 'director', null, 't1', 'e2');

        self::assertSame(403, $teacher->getStatusCode());
        self::assertSame(403, $inspector->getStatusCode());
        self::assertSame(404, $otherDistrict->getStatusCode());
        self::assertSame(404, $teacherRead->getStatusCode(), 'Un enseignant ne lit pas la fiche de carrière de ses collègues.');
        self::assertSame(404, $unknown->getStatusCode());
        self::assertContains($otherSchool->getStatusCode(), [403, 404]);
        self::assertSame([], $this->rows('SELECT id FROM pub_staff_profiles'));
        self::assertSame([], $this->journal->entries);
    }

    public function testLaListeDuPersonnelEstPagineeEtMasqueLaCarrierePourLInspection(): void
    {
        $this->api('PUT', '/public/staff/p-teacher/profile', 'director', $this->profile());

        [, $first] = $this->api('GET', '/public/staff?per_page=2', 'director');
        [, $second] = $this->api('GET', '/public/staff?per_page=2&cursor=' . $first['meta']['next_cursor'], 'director');
        [$inspector, $inspectorJson] = $this->api('GET', '/public/staff?purpose=transfer', 'inspector');
        [$tooMany] = $this->api('GET', '/public/staff?per_page=201', 'director');

        self::assertCount(2, $first['data']);
        self::assertNotNull($first['meta']['next_cursor']);
        self::assertCount(1, $second['data']);
        self::assertNull($second['meta']['next_cursor']);
        $byId = array_column(array_merge($first['data'], $second['data']), null, 'id');
        self::assertSame('SYN-FP-000123', $byId['p-teacher']['civil_service_number']);
        self::assertSame('Aimé Mballa', $byId['p-teacher']['name']);
        self::assertSame(200, $inspector->getStatusCode(), (string) $inspector->getBody());
        self::assertCount(3, $inspectorJson['data']);
        self::assertArrayNotHasKey('civil_service_number', $inspectorJson['data'][0]);
        self::assertSame(422, $tooMany->getStatusCode());
    }

    public function testLesFormationsSontReserveesALEcole(): void
    {
        [$created, $json] = $this->api('POST', '/public/staff/p-teacher/trainings', 'director', ['title' => 'Journée pédagogique SYN', 'starts_on' => '2026-09-15', 'ends_on' => '2026-09-16', 'organiser' => 'Inspection d\'arrondissement']);
        $this->api('POST', '/public/staff/p-teacher/trainings', 'director', ['title' => 'Formation continue SYN', 'starts_on' => '2026-10-01']);

        [$list, $listJson] = $this->api('GET', '/public/staff/p-teacher/trainings?per_page=1', 'director');
        [$inspector] = $this->api('GET', '/public/staff/p-teacher/trainings?purpose=control', 'inspector');
        [$invalid, $invalidJson] = $this->api('POST', '/public/staff/p-teacher/trainings', 'director', ['starts_on' => '2026-10-01', 'ends_on' => '2026-09-01']);
        [$teacher] = $this->api('POST', '/public/staff/p-teacher/trainings', 'teacher', ['title' => 'X', 'starts_on' => '2026-10-01']);

        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame('Journée pédagogique SYN', $json['data']['title']);
        self::assertSame(200, $list->getStatusCode());
        self::assertCount(1, $listJson['data']);
        self::assertNotNull($listJson['meta']['next_cursor']);
        self::assertSame(403, $inspector->getStatusCode(), 'RG-PER-2 : l\'inspection ne voit pas les formations.');
        self::assertSame(422, $invalid->getStatusCode());
        self::assertArrayHasKey('title', $invalidJson['errors']);
        self::assertArrayHasKey('ends_on', $invalidJson['errors']);
        self::assertSame(403, $teacher->getStatusCode());
        self::assertCount(2, $this->rows('SELECT id FROM pub_trainings'));
    }

    public function testUneMutationDansLeMemeDepartementEstEnregistreeAvecSonHistorique(): void
    {
        [$moved, $json] = $this->api('POST', '/public/staff/p-teacher/moves', 'director', ['to_establishment_id' => 'e2', 'effective_on' => '2026-11-02', 'reason' => 'Rapprochement de conjoints']);
        [$history, $historyJson] = $this->api('GET', '/public/staff/p-teacher/moves', 'director');

        self::assertSame(201, $moved->getStatusCode(), (string) $moved->getBody());
        self::assertSame('e2', $json['data']['to_establishment_id']);
        self::assertSame('st-teacher', $json['data']['staff_id']);
        self::assertSame(200, $history->getStatusCode());
        self::assertCount(1, $historyJson['data']);
        self::assertSame('Rapprochement de conjoints', $historyJson['data'][0]['reason']);
        self::assertCount(1, $this->rows("SELECT action FROM audit_log WHERE action = 'public_schools.staff.moved'"));
    }

    public function testUneMutationHorsDuTerritoireOuMalFormeeEstRefusee(): void
    {
        $this->territories->attach('t1', 'e3', 'arr-3');
        [$far, $farJson] = $this->api('POST', '/public/staff/p-teacher/moves', 'director', ['to_establishment_id' => 'e3', 'effective_on' => '2026-11-02']);
        [$nowhere, $nowhereJson] = $this->api('POST', '/public/staff/p-teacher/moves', 'director', ['to_establishment_id' => 'e9', 'effective_on' => '2026-11-02']);
        [$bad, $badJson] = $this->api('POST', '/public/staff/p-teacher/moves', 'director', ['effective_on' => 'demain']);
        [$teacher] = $this->api('POST', '/public/staff/p-teacher/moves', 'teacher', ['to_establishment_id' => 'e2', 'effective_on' => '2026-11-02']);
        [$inspector] = $this->api('POST', '/public/staff/p-teacher/moves', 'inspector', ['to_establishment_id' => 'e2', 'effective_on' => '2026-11-02']);

        self::assertSame(422, $far->getStatusCode(), (string) $far->getBody());
        self::assertArrayHasKey('to_establishment_id', $farJson['errors']);
        self::assertSame(422, $nowhere->getStatusCode());
        self::assertArrayHasKey('to_establishment_id', $nowhereJson['errors']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertArrayHasKey('effective_on', $badJson['errors']);
        self::assertSame(403, $teacher->getStatusCode());
        self::assertSame(403, $inspector->getStatusCode());
        self::assertSame([], $this->mover->moves);
    }

    public function testLeNiveauDeTerritoireDesMutationsEstUnParametre(): void
    {
        $this->territories->attach('t1', 'e3', 'arr-3');
        $this->settings->values['public_schools.staff.move_level'] = 'region';

        [$response] = $this->api('POST', '/public/staff/p-teacher/moves', 'director', ['to_establishment_id' => 'e3', 'effective_on' => '2026-11-02']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testLesBesoinsEnEnseignantsComptentLesElevesParEnseignantEtLesClassesSansTitulaire(): void
    {
        [$response, $json] = $this->api('GET', '/public/staff/needs', 'director');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(3, $json['data']['teachers']);
        self::assertSame(5, $json['data']['pupils']);
        self::assertSame(2, $json['data']['classes']);
        self::assertSame(1.7, $json['data']['pupils_per_teacher']);
        self::assertNull($json['data']['teachers_missing'], 'Sans objectif paramétré, aucun manque n\'est inventé.');
        self::assertSame([['structure_id' => 'c2', 'label' => 'CE1']], $json['data']['classes_without_homeroom']);

        $this->settings->values['public_schools.staff.pupils_per_teacher_target'] = 1;
        [, $withTarget] = $this->api('GET', '/public/staff/needs', 'inspector');
        self::assertSame(2, $withTarget['data']['teachers_missing']);
        self::assertStringNotContainsString('Mballa', (string) json_encode($withTarget['data']), 'Les besoins ne portent aucun nom.');
    }

    public function testLesBesoinsNeSontPasLisiblesParUnEnseignantOuUnAutreArrondissement(): void
    {
        [$teacher] = $this->api('GET', '/public/staff/needs', 'teacher');
        [$other] = $this->api('GET', '/public/staff/needs', 'inspector2');
        [$outsider] = $this->api('GET', '/public/staff/needs', 'outsider');

        self::assertSame(404, $teacher->getStatusCode());
        self::assertSame(404, $other->getStatusCode());
        self::assertSame(404, $outsider->getStatusCode());
    }
}
