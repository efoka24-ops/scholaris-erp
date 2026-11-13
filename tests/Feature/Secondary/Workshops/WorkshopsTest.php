<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Workshops;

/**
 * E3-C (T908 à T910) : ateliers paramétrables, garde EPI (RG-ATL-1), grille pratique d'exemple 12/20 et plafond d'exemple,
 * matière d'œuvre et coût par élève, registre de sécurité, stages, droits et isolation. Données fictives.
 */
final class WorkshopsTest extends WorkshopWorld
{
    private const OBSERVED = ['preparation' => 3, 'safety' => 2, 'execution' => 4, 'quality' => 2, 'time' => 1];

    public function testSeanceSansEpiEnObservationEtGrilleDExemple(): void
    {
        $school = $this->school('lycee_technique');
        $classId = $this->firstCycleClass($school, ['learner-observer', 'learner-protected', 'learner-breach']);
        $workshop = $this->ok('POST', '/secondary/workshops', ['label' => 'Atelier fictif'], self::ADMIN, self::TA, $school);
        $sheet = $this->exampleSheet($school, $workshop['id']);
        $machine = $this->ok('POST', '/secondary/workshops/workstations', ['workshop_id' => $workshop['id'], 'label' => 'Machine fictive', 'machine' => true, 'ppe_required' => ['gloves', 'goggles']], self::ADMIN, self::TA, $school);
        $bench = $this->ok('POST', '/secondary/workshops/workstations', ['workshop_id' => $workshop['id'], 'label' => 'Établi fictif', 'machine' => false, 'ppe_required' => []], self::ADMIN, self::TA, $school);
        $draft = [
            'workshop_id' => $workshop['id'], 'structure_id' => $classId, 'sheet_id' => $sheet['id'], 'session_date' => '2026-10-20',
            'teacher_person_id' => 'teacher-fictive', 'client_uuid' => 'uuid-workshop-01',
            'learners' => [
                ['person_id' => 'learner-observer', 'workstation_id' => $machine['id'], 'ppe_items' => ['goggles']],
                ['person_id' => 'learner-protected', 'workstation_id' => $bench['id'], 'ppe_items' => []],
            ],
        ];
        $created = $this->ok('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', $draft, self::ADMIN, self::TA, $school);
        $attendance = array_column($created['session']['attendance'], null, 'person_id');
        self::assertSame('observation', $attendance['learner-observer']['mode']);
        self::assertNull($attendance['learner-observer']['workstation_id'], 'RG-ATL-1 : aucun poste machine sans tous les EPI.');
        self::assertSame(['gloves'], $attendance['learner-observer']['missing_ppe']);
        self::assertSame('workstation', $attendance['learner-protected']['mode'], 'Un poste sans machine ne demande pas d’EPI.');
        self::assertSame(422, $this->call('POST', '/secondary/workshops/sessions/' . $created['session']['id'] . '/evaluations', ['person_id' => 'learner-observer', 'observed' => ['preparation' => 3], 'serious_safety_breach' => false], self::ADMIN, self::TA, $school)[0]);
        [$status, $replay] = $this->call('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', $draft, self::ADMIN, self::TA, $school);
        self::assertSame(200, $status, 'Rejeu client_uuid idempotent.');
        self::assertTrue($replay['data']['replayed'] ?? $replay['replayed']);

        $draft['client_uuid'] = 'uuid-workshop-02';
        $draft['learners'] = [['person_id' => 'learner-protected', 'workstation_id' => $machine['id'], 'ppe_items' => ['gloves', 'goggles']], ['person_id' => 'learner-breach', 'workstation_id' => $bench['id']]];
        $protected = $this->ok('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', $draft, self::ADMIN, self::TA, $school);
        self::assertSame('workstation', $protected['session']['attendance'][0]['mode']);
        self::assertSame(422, $this->call('POST', '/secondary/workshops/sessions/' . $protected['session']['id'] . '/evaluations', ['person_id' => 'learner-protected', 'observed' => ['preparation' => 5], 'serious_safety_breach' => false], self::ADMIN, self::TA, $school)[0], 'Points au-delà du critère.');
        $score = $this->ok('POST', '/secondary/workshops/sessions/' . $protected['session']['id'] . '/evaluations', ['person_id' => 'learner-protected', 'observed' => self::OBSERVED, 'serious_safety_breach' => false], self::ADMIN, self::TA, $school);
        self::assertEquals(12.0, (float) $score['final_score'], 'Grille d’exemple : 3 + 2 + 4 + 2 + 1 = 12/20.');
        self::assertFalse($score['score_details']['capped']);
        self::assertSame(409, $this->call('POST', '/secondary/workshops/sessions/' . $protected['session']['id'] . '/evaluations', ['person_id' => 'learner-protected', 'observed' => self::OBSERVED, 'serious_safety_breach' => false], self::ADMIN, self::TA, $school)[0], 'Une évaluation par élève et par séance.');
        $capped = $this->ok('POST', '/secondary/workshops/sessions/' . $protected['session']['id'] . '/evaluations', ['person_id' => 'learner-breach', 'observed' => self::OBSERVED, 'serious_safety_breach' => true], self::ADMIN, self::TA, $school);
        self::assertEquals(8.0, (float) $capped['final_score'], 'Plafond d’exemple de la fiche en cas de manquement grave.');
        self::assertEquals(12.0, (float) $capped['raw_total']);
    }

    public function testSeanceRefuseUneClasseEtrangereOuUnElevNonInscrit(): void
    {
        $school = $this->school('lycee_technique');
        $classId = $this->firstCycleClass($school, ['learner-a']);
        $workshop = $this->ok('POST', '/secondary/workshops', ['label' => 'Atelier fictif'], self::ADMIN, self::TA, $school);
        $sheet = $this->exampleSheet($school, $workshop['id']);
        $draft = ['workshop_id' => $workshop['id'], 'structure_id' => 'class-inconnue', 'sheet_id' => $sheet['id'], 'session_date' => '2026-10-20', 'teacher_person_id' => 'teacher-fictive', 'client_uuid' => 'uuid-x1', 'learners' => [['person_id' => 'learner-a']]];
        self::assertSame(404, $this->call('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', $draft, self::ADMIN, self::TA, $school)[0], 'Classe hors établissement.');
        $draft['structure_id'] = $classId;
        $draft['learners'] = [['person_id' => 'learner-inconnu']];
        self::assertSame(422, $this->call('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', $draft, self::ADMIN, self::TA, $school)[0], 'Élève non inscrit.');
        $draft['learners'] = [['person_id' => 'learner-a'], ['person_id' => 'learner-a']];
        self::assertSame(422, $this->call('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', $draft, self::ADMIN, self::TA, $school)[0], 'Élève en double.');
        self::assertSame(422, $this->call('POST', '/secondary/workshops/sheets', ['workshop_id' => $workshop['id'], 'title' => 'TP', 'objective' => 'x', 'materials' => [], 'tools' => [], 'safety_rules' => [], 'criteria' => [['code' => 'a', 'label' => 'A', 'points' => 4]], 'base_score' => 20, 'safety_cap' => 25], self::ADMIN, self::TA, $school)[0], 'Plafond au-delà de la base.');
    }

    public function testMatiereDOeuvreCoutParEleveEtRegistreDeSecurite(): void
    {
        $school = $this->school('cetic');
        $classId = $this->firstCycleClass($school, ['learner-1', 'learner-2', 'learner-3', 'learner-4']);
        $workshop = $this->ok('POST', '/secondary/workshops', ['label' => 'Atelier CETIC fictif'], self::ADMIN, self::TA, $school);
        $sheet = $this->exampleSheet($school, $workshop['id']);
        $session = $this->ok('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', [
            'workshop_id' => $workshop['id'], 'structure_id' => $classId, 'sheet_id' => $sheet['id'], 'session_date' => '2026-10-21', 'teacher_person_id' => 'teacher-fictive',
            'client_uuid' => 'uuid-stock', 'learners' => [['person_id' => 'learner-1'], ['person_id' => 'learner-2'], ['person_id' => 'learner-3'], ['person_id' => 'learner-4']],
        ], self::ADMIN, self::TA, $school)['session'];
        $item = $this->ok('POST', '/secondary/workshops/materials', ['workshop_id' => $workshop['id'], 'label' => 'Consommable fictif', 'unit_label' => 'unité', 'unit_cost' => 125, 'stock_qty' => 5], self::ADMIN, self::TA, $school);
        $move = $this->ok('POST', '/secondary/workshops/materials/' . $item['id'] . '/moves', ['move_kind' => 'out', 'quantity' => 2, 'session_id' => $session['id']], self::ADMIN, self::TA, $school);
        self::assertSame(250, $move['total_cost_xaf']);
        self::assertSame(4, $move['learner_count']);
        self::assertSame(63, $move['cost_per_learner_xaf'], 'Coût par élève arrondi au franc.');
        self::assertSame(3.0, (float) $move['stock_qty']);
        self::assertSame(422, $this->call('POST', '/secondary/workshops/materials/' . $item['id'] . '/moves', ['move_kind' => 'out', 'quantity' => 4], self::ADMIN, self::TA, $school)[0], 'Stock négatif refusé.');
        $event = $this->ok('POST', '/secondary/workshops/safety-events?workshop_id=' . $workshop['id'], ['event_kind' => 'ppe_check', 'description' => 'Contrôle fictif', 'session_id' => $session['id']], self::ADMIN, self::TA, $school);
        self::assertSame('ppe_check', $event['event_kind']);
        self::assertCount(1, $this->ok('GET', '/secondary/workshops/safety-events?workshop_id=' . $workshop['id'], null, self::ADMIN, self::TA, $school));
        $other = $this->ok('POST', '/secondary/workshops', ['label' => 'Autre atelier'], self::ADMIN, self::TA, $school);
        self::assertSame(404, $this->call('POST', '/secondary/workshops/safety-events?workshop_id=' . $other['id'], ['event_kind' => 'incident', 'description' => 'x', 'session_id' => $session['id']], self::ADMIN, self::TA, $school)[0], 'Séance d’un autre atelier.');
        self::assertContains('secondary.workshop.material.moved', $this->audit->actions());
        self::assertContains('secondary.workshop.safety.recorded', $this->audit->actions());
    }

    public function testStageEntrepriseConventionNoteParCoefficientEtVisites(): void
    {
        $school = $this->school('lycee_technique');
        $classId = $this->firstCycleClass($school, ['learner-fictive']);
        $company = $this->ok('POST', '/secondary/internships/companies', ['label' => 'Entreprise fictive', 'sector' => 'Technique'], self::ADMIN, self::TA, $school);
        $internship = $this->ok('POST', '/secondary/internships', [
            'person_id' => 'learner-fictive', 'structure_id' => $classId, 'company_id' => $company['id'], 'tutor_name' => 'Tuteur fictif', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30',
            'score_coefficient' => 1.5,
        ], self::ADMIN, self::TA, $school);
        self::assertSame('Entreprise fictive', $internship['company']['label']);
        $updated = $this->ok('PATCH', '/secondary/internships/' . $internship['id'], [
            'agreement_document_id' => 'doc-fictif-1', 'tutor_appraisal' => 'Évaluation fictive', 'report_received_on' => '2026-07-01', 'score' => 14.5, 'status' => 'completed',
        ], self::ADMIN, self::TA, $school);
        self::assertSame('completed', $updated['status']);
        self::assertSame(21.75, $updated['weighted_score'], 'Note × coefficient paramétré.');
        $visit = $this->ok('POST', '/secondary/internships/' . $internship['id'] . '/visits', ['visitor_person_id' => 'teacher-fictive', 'visited_on' => '2026-06-15', 'notes' => 'Visite fictive'], self::ADMIN, self::TA, $school);
        self::assertSame($internship['id'], $visit['internship_id']);
        self::assertCount(1, $this->ok('GET', '/secondary/internships/visits?internship_id=' . $internship['id'], null, self::ADMIN, self::TA, $school));
        self::assertCount(1, $this->ok('GET', '/secondary/internships', null, self::ADMIN, self::TA, $school));
        $base = ['person_id' => 'learner-fictive', 'structure_id' => $classId, 'company_id' => $company['id'], 'tutor_name' => 'Tuteur', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-30'];
        self::assertSame(422, $this->call('POST', '/secondary/internships', ['ends_on' => '2026-06-01'] + $base, self::ADMIN, self::TA, $school)[0], 'Fin avant début.');
        self::assertSame(422, $this->call('POST', '/secondary/internships', ['person_id' => 'learner-inconnu'] + $base, self::ADMIN, self::TA, $school)[0], 'Élève non inscrit.');
        self::assertSame(404, $this->call('POST', '/secondary/internships', ['structure_id' => 'class-inconnue'] + $base, self::ADMIN, self::TA, $school)[0], 'Classe hors établissement.');
    }

    public function testDroitsPerimetreEtIsolationEntreClientsEtEtablissements(): void
    {
        $school = $this->school('lycee_technique');
        $classId = $this->firstCycleClass($school, ['learner-a']);
        $workshop = $this->ok('POST', '/secondary/workshops', ['label' => 'Atelier isolé'], self::ADMIN, self::TA, $school);
        $sheet = $this->exampleSheet($school, $workshop['id']);
        $this->ok('POST', '/secondary/workshops/' . $workshop['id'] . '/sessions', ['workshop_id' => $workshop['id'], 'structure_id' => $classId, 'sheet_id' => $sheet['id'], 'session_date' => '2026-10-20', 'teacher_person_id' => 't', 'client_uuid' => 'uuid-iso', 'learners' => [['person_id' => 'learner-a']]], self::ADMIN, self::TA, $school);

        $schoolB = $this->school('cetic', self::TB, 'admin-b');
        self::assertSame(404, $this->call('PATCH', '/secondary/workshops/' . $workshop['id'], ['label' => 'Intrusion'], 'admin-b', self::TB, $schoolB)[0], 'Autre client : 404.');
        self::assertSame(404, $this->call('GET', '/secondary/workshops/sheets?workshop_id=' . $workshop['id'], null, 'admin-b', self::TB, $schoolB)[0]);
        self::assertSame([], $this->ok('GET', '/secondary/workshops', null, 'admin-b', self::TB, $schoolB));

        $sibling = $this->factory->opened(self::TA, 'cetic', null, 'e-cetic-soeur');
        $this->grant(self::TA, self::ADMIN, $sibling, self::PERMISSIONS);
        self::assertSame(404, $this->call('GET', '/secondary/workshops/sessions?workshop_id=' . $workshop['id'], null, self::ADMIN, self::TA, $sibling)[0], 'Autre établissement du même client : 404.');

        $this->grant(self::TA, 'lecteur', $school, ['secondary.workshops.read']);
        self::assertSame(403, $this->call('POST', '/secondary/workshops', ['label' => 'Interdit'], 'lecteur', self::TA, $school)[0]);
        self::assertSame(403, $this->call('GET', '/secondary/workshops/materials?workshop_id=' . $workshop['id'], null, 'lecteur', self::TA, $school)[0], 'Coûts réservés.');
        self::assertSame(403, $this->call('GET', '/secondary/internships', null, 'lecteur', self::TA, $school)[0]);
        $this->grant(self::TA, 'enseignant', $school, ['secondary.workshops.session'], 'class', 'autre-classe');
        self::assertSame([], $this->ok('GET', '/secondary/workshops/sessions?workshop_id=' . $workshop['id'], null, 'enseignant', self::TA, $school), 'Séances filtrées par le périmètre de classes.');
    }
}
