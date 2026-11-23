<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Workshops;

/**
 * DOC-21 à DOC-24 émis par Workshops par le contrat SecondaryDocuments (E3-F) aux moments du parcours : convention au stage
 * préparé, fiche d'appréciation après l'appréciation du tuteur, fiche de TP remise à un élève, état du stock par le chef des
 * travaux. Module et enseignement technique exigés. Données fictives.
 */
final class WorkshopDocumentsTest extends WorkshopWorld
{
    public function testConventionEtFicheDAppreciationDeStage(): void
    {
        $school = $this->school('lycee_technique');
        $classId = $this->firstCycleClass($school, ['stagiaire']);
        $company = $this->ok('POST', '/secondary/internships/companies', ['label' => 'Entreprise fictive', 'address' => 'Zone fictive'], self::ADMIN, self::TA, $school);
        $internship = $this->ok('POST', '/secondary/internships', ['person_id' => 'stagiaire', 'structure_id' => $classId, 'company_id' => $company['id'], 'tutor_name' => 'Tuteur fictif', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30'], self::ADMIN, self::TA, $school);

        $agreement = $this->ok('POST', '/secondary/internships/' . $internship['id'] . '/agreement', [], self::ADMIN, self::TA, $school);
        self::assertSame('sec_doc21_internship_agreement', $agreement['type']);
        self::assertNotSame('', $agreement['number']);
        $rows = array_column($this->ok('GET', '/secondary/internships', null, self::ADMIN, self::TA, $school), null, 'id');
        self::assertSame($agreement['document_id'], $rows[$internship['id']]['agreement_document_id'], 'La convention est rattachée au stage.');

        self::assertSame(422, $this->call('POST', '/secondary/internships/' . $internship['id'] . '/appraisal', [], self::ADMIN, self::TA, $school)[0], 'Appréciation du tuteur d’abord.');
        $this->ok('PATCH', '/secondary/internships/' . $internship['id'], ['tutor_appraisal' => 'Appréciation fictive', 'status' => 'completed'], self::ADMIN, self::TA, $school);
        $appraisal = $this->ok('POST', '/secondary/internships/' . $internship['id'] . '/appraisal', ['locale' => 'en'], self::ADMIN, self::TA, $school);
        self::assertSame('sec_doc22_trainee_appraisal', $appraisal['type']);
        self::assertSame(409, $this->call('POST', '/secondary/internships/' . $internship['id'] . '/agreement', [], self::ADMIN, self::TA, $school)[0], 'Plus de convention après la fin du stage.');
        self::assertContains('secondary.internship.agreement.issued', $this->audit->actions());
    }

    public function testFicheDeTravauxPratiquesEtEtatDuStock(): void
    {
        $school = $this->school('cetic');
        $classId = $this->firstCycleClass($school, ['eleve-tp', 'eleve-2']);
        $workshop = $this->ok('POST', '/secondary/workshops', ['label' => 'Atelier fictif', 'chief_person_id' => 'chef-travaux'], self::ADMIN, self::TA, $school);
        $this->people->add(self::TA, 'chef-travaux', 'Chef', 'Travaux');
        $sheet = $this->exampleSheet($school, $workshop['id']);
        $doc23 = $this->ok('POST', '/secondary/workshops/sheets/' . $sheet['id'] . '/documents', ['structure_id' => $classId, 'person_id' => 'eleve-tp'], self::ADMIN, self::TA, $school);
        self::assertSame('sec_doc23_workshop_sheet', $doc23['type']);
        self::assertSame(422, $this->call('POST', '/secondary/workshops/sheets/' . $sheet['id'] . '/documents', ['structure_id' => $classId, 'person_id' => 'inconnu'], self::ADMIN, self::TA, $school)[0]);

        $session = $this->ok('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', ['workshop_id' => $workshop['id'], 'structure_id' => $classId, 'sheet_id' => $sheet['id'], 'session_date' => '2026-10-21', 'teacher_person_id' => 't', 'client_uuid' => 'uuid-doc24', 'learners' => [['person_id' => 'eleve-tp'], ['person_id' => 'eleve-2']]], self::ADMIN, self::TA, $school)['session'];
        $item = $this->ok('POST', '/secondary/workshops/materials', ['workshop_id' => $workshop['id'], 'label' => 'Consommable fictif', 'unit_label' => 'unité', 'unit_cost' => 100, 'stock_qty' => 10], self::ADMIN, self::TA, $school);
        $this->ok('POST', '/secondary/workshops/materials/' . $item['id'] . '/moves', ['move_kind' => 'out', 'quantity' => 3, 'session_id' => $session['id']], self::ADMIN, self::TA, $school);
        $doc24 = $this->ok('POST', '/secondary/workshops/' . $workshop['id'] . '/stock-report', [], self::ADMIN, self::TA, $school);
        self::assertSame('sec_doc24_material_stock', $doc24['type']);
        self::assertSame(150, $doc24['cost_per_learner_xaf'], '300 FCFA de matière pour 2 élèves.');

        $this->grant(self::TA, 'enseignant', $school, ['secondary.workshops.session']);
        self::assertSame(403, $this->call('POST', '/secondary/workshops/' . $workshop['id'] . '/stock-report', [], 'enseignant', self::TA, $school)[0], 'État du stock réservé au chef des travaux.');
    }

    public function testDocumentTechniqueRefuseSansEnseignementTechnique(): void
    {
        $leg = $this->school('leg');
        $profile = $this->ok('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $leg);
        $active = array_values(array_map(static fn (array $m): string => $m['code'], array_filter($profile['modules'], static fn (array $m): bool => $m['state'] === 'active')));
        $this->ok('POST', '/secondary/setup/profile/changes', ['kind' => 'modules', 'value' => array_merge($active, ['workshops']), 'reason' => 'Atelier de démonstration'], self::ADMIN, self::TA, $leg);
        $workshop = $this->ok('POST', '/secondary/workshops', ['label' => 'Atelier LEG', 'chief_person_id' => 'chef'], self::ADMIN, self::TA, $leg);
        [$status, $problem] = $this->call('POST', '/secondary/workshops/' . $workshop['id'] . '/stock-report', [], self::ADMIN, self::TA, $leg);
        self::assertSame(409, $status, 'Lycée général : enseignement technique non offert.');
        self::assertSame('profile_capability_not_offered', $problem['code'] ?? null);
    }
}
