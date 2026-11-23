<?php

declare(strict_types=1);

namespace Tests\Feature\PublicPrimary\Exams;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\PublicSchools\PublicPrimaryTestCase;

/**
 * Lot P3, examens (T680 à T685) : session nationale, liste du CM2 générée, circuit directeur > inspection > délégation (API-09),
 * candidat sans acte vérifié bloqué seul (TST-07), gel et ajout motivé (RG-EXA-2), centres et numéros, import (API-10), taux
 * = admis ÷ présents, publication (NOT-09), attestations jamais diplôme (RG-EXA-3), vacations (décret 2010/1738, taux en paramètres),
 * dossier signé vers le secondaire. Données fictives.
 */
final class ExamCircuitTest extends PublicPrimaryTestCase
{
    private string $session = '';

    protected function setUp(): void
    {
        $this->bootPrimary();
        $this->classes->add('t1', 'e1', 'c3', 'CM2 A', ['CM2'], null);
        foreach (['m1' => ['Alice', true, 'F'], 'm2' => ['Bruno', true, 'M'], 'm3' => ['Chantal', false, 'F']] as $id => [$first, $verified, $sex]) {
            $this->people->add('t1', $id, $first, 'Mbarga');
            $this->enrollments->add('t1', 'en-' . $id, $id, 'e1', 'c3');
            $this->registerPupil($id, $first, $verified, $sex);
        }
        $this->people->add('t1', 'p-parent-m3', 'Odile', 'Mbarga', '+237670000033');
        $this->people->link('t1', 'p-parent-m3', 'm3');

        $this->permissions->grant('director', 'public_primary.exams.write');
        $this->permissions->grant('teacher2', 'public_primary.exams.read');
        $this->permissions->grant('inspector', 'public_primary.exams.validate');
        $this->actor('delegate', 'p-delegate', ['public_primary.exams.validate', 'public_primary.exams.import', 'public_primary.exams.consolidate', 'public_primary.exams.attest', 'documents.read'], null);
        $this->scopes->give('delegate', Scope::territory('dep-1', 'pub_departmental_delegate', 't1', 'e1'), Scope::territory('dep-1', 'pub_departmental_delegate', 't1', 'e2'));
        $this->people->add('t1', 'p-delegate', 'Didier', 'Elegue');
        $this->actor('examdir', 'p-examdir', ['public_primary.exams.consolidate', 'public_primary.exams.import'], new Scope(Scope::ESTABLISHMENT, 'e1', 'pub_exam_directorate'));
        $this->people->add('t1', 'p-examdir', 'Esther', 'Xamen');
    }

    public function testLeCircuitCompletDuCepJusquALAttestation(): void
    {
        [$denied] = $this->api('POST', '/public-primary/exams', 'director', ['exam_code' => 'cep', 'year_label' => '2026-2027']);
        self::assertContains($denied->getStatusCode(), [403, 404], 'Les sessions sont ouvertes au niveau national.');
        $this->openSession();

        [$generated, $list] = $this->api('POST', '/public-primary/exams/' . $this->session . '/candidates/generate', 'director', ['academic_year_id' => 'y1']);
        self::assertSame(201, $generated->getStatusCode(), (string) $generated->getBody());
        self::assertCount(3, $list['data']);
        self::assertSame(['proposed'], array_values(array_unique(array_column($list['data'], 'state'))));

        // NOT-08 : la famille du candidat sans acte vérifié est prévenue, une seule fois.
        [$alerts, $alertsJson] = $this->api('POST', '/public-primary/exams/' . $this->session . '/birth-act-alerts', 'director', []);
        self::assertSame(200, $alerts->getStatusCode(), (string) $alerts->getBody());
        self::assertSame(1, $alertsJson['data']['alerted']);
        [, $again] = $this->api('POST', '/public-primary/exams/' . $this->session . '/birth-act-alerts', 'director', []);
        self::assertSame(0, $again['data']['alerted']);

        [$early] = $this->api('POST', '/public-primary/exams/' . $this->session . '/candidates/validate', 'inspector', []);
        self::assertSame(409, $early->getStatusCode(), 'Rien à valider avant la transmission.');
        [$submitted] = $this->api('POST', '/public-primary/exams/' . $this->session . '/submit', 'director', []);
        self::assertSame(200, $submitted->getStatusCode(), (string) $submitted->getBody());

        [$wrongStep] = $this->api('POST', '/public-primary/exams/' . $this->session . '/candidates/validate', 'delegate', []);
        self::assertSame(403, $wrongStep->getStatusCode(), 'Première étape : l\'inspection d\'arrondissement.');

        // TST-07 : la validation de l'inspection est bloquée pour le seul candidat sans acte vérifié.
        [$inspected, $inspection] = $this->api('POST', '/public-primary/exams/' . $this->session . '/candidates/validate', 'inspector', []);
        self::assertSame(200, $inspected->getStatusCode(), (string) $inspected->getBody());
        self::assertSame(2, $inspection['data']['validated']);
        self::assertCount(1, $inspection['data']['blocked']);
        self::assertSame('inspected', $inspection['data']['list']['state']);
        $states = $this->statesByPerson();
        self::assertSame(['m1' => 'validated', 'm2' => 'validated', 'm3' => 'blocked'], $states);

        [$frozen, $freeze] = $this->api('POST', '/public-primary/exams/' . $this->session . '/candidates/validate', 'delegate', []);
        self::assertSame(200, $frozen->getStatusCode(), (string) $frozen->getBody());
        self::assertSame('validated', $freeze['data']['list']['state']);
        self::assertNotNull($freeze['data']['list']['frozen_at']);

        // RG-EXA-2 : après le gel, tout ajout est motivé et tracé.
        $this->people->add('t1', 'm4', 'Denis', 'Mbarga');
        $this->enrollments->add('t1', 'en-m4', 'm4', 'e1', 'c3');
        $this->registerPupil('m4', 'Denis', true, 'M');
        [$unmotivated] = $this->api('POST', '/public-primary/exams/' . $this->session . '/candidates', 'director', ['person_id' => 'm4', 'structure_id' => 'c3']);
        self::assertSame(422, $unmotivated->getStatusCode());
        [$added, $addedJson] = $this->api('POST', '/public-primary/exams/' . $this->session . '/candidates', 'director', ['person_id' => 'm4', 'structure_id' => 'c3', 'reason' => 'Arrivé par transfert après le gel (fictif).']);
        self::assertSame(201, $added->getStatusCode(), (string) $added->getBody());
        self::assertTrue($addedJson['data']['added_after_freeze']);
        [, $show] = $this->api('GET', '/public-primary/exams/' . $this->session, 'director');
        self::assertSame(1, $show['data']['list']['amendments_count']);

        // Acte vérifié ensuite : la délégation valide le candidat bloqué (liste figée).
        $blockedId = $this->candidateId('m3');
        [$stillBlocked] = $this->api('POST', '/public-primary/exam-candidates/' . $blockedId . '/validate', 'delegate', []);
        self::assertSame(409, $stillBlocked->getStatusCode());
        $this->connections()->pdo()->prepare('UPDATE pub_pupil_profiles SET birth_act_verified = 1 WHERE person_id = ?')->execute(['m3']);
        [$byInspector] = $this->api('POST', '/public-primary/exam-candidates/' . $blockedId . '/validate', 'inspector', []);
        self::assertSame(403, $byInspector->getStatusCode(), 'Liste figée : seule la délégation valide.');
        [$late] = $this->api('POST', '/public-primary/exam-candidates/' . $blockedId . '/validate', 'delegate', []);
        self::assertSame(200, $late->getStatusCode(), (string) $late->getBody());

        // Centre et numéros de candidats.
        [$centre, $centreJson] = $this->api('POST', '/public-primary/exams/' . $this->session . '/centres', 'delegate', ['territory_id' => 'dep-1', 'code' => 'SYN-CEP-01', 'label' => 'Centre fictif de Bonamoussadi', 'host_establishment_id' => 'e1']);
        self::assertSame(201, $centre->getStatusCode(), (string) $centre->getBody());
        [$outside] = $this->api('POST', '/public-primary/exams/' . $this->session . '/centres', 'delegate', ['territory_id' => 'dep-2', 'code' => 'SYN-CEP-02', 'label' => 'Hors territoire']);
        self::assertSame(403, $outside->getStatusCode());
        [$assigned, $assignJson] = $this->api('POST', '/public-primary/exams/' . $this->session . '/centres/' . $centreJson['data']['id'] . '/assign', 'delegate', ['establishment_ids' => ['e1']]);
        self::assertSame(200, $assigned->getStatusCode(), (string) $assigned->getBody());
        self::assertSame(3, $assignJson['data']['assigned']);
        self::assertSame(1, $assignJson['data']['skipped'], 'L\'ajout encore proposé n\'est pas affecté.');
        $numbers = $this->numbersByPerson();
        self::assertSame('SYN-CEP-01-0001', $numbers['m1']);

        // API-10 : import des résultats officiels ; taux = admis ÷ présents.
        [$denyImport] = $this->api('POST', '/public-primary/exams/' . $this->session . '/results/import', 'director', ['territory_id' => 'dep-1', 'rows' => []]);
        self::assertContains($denyImport->getStatusCode(), [403, 404]);
        [$imported, $importJson] = $this->api('POST', '/public-primary/exams/' . $this->session . '/results/import', 'delegate', [
            'territory_id' => 'dep-1',
            'csv' => "candidate_number;result\n" . $numbers['m1'] . ";admis\n" . $numbers['m2'] . ";ajourné\n" . $numbers['m3'] . ";absent\nSYN-X;admis\n",
        ]);
        self::assertSame(200, $imported->getStatusCode(), (string) $imported->getBody());
        self::assertSame(3, $importJson['data']['imported']);
        self::assertCount(1, $importJson['data']['rejected']);
        self::assertEquals(50.0, $importJson['data']['summary']['success_rate'], '1 admis ÷ 2 présents (l\'absent ne compte pas).');

        [, $before] = $this->api('GET', '/public-primary/exams/' . $this->session . '/results', 'director');
        self::assertSame(3, $before['data']['summary']['pending'], 'L\'école ne voit les résultats qu\'une fois publiés.');
        [$published, $publishJson] = $this->api('POST', '/public-primary/exams/' . $this->session . '/results/publish', 'delegate', ['territory_id' => 'dep-1']);
        self::assertSame(200, $published->getStatusCode(), (string) $published->getBody());
        self::assertSame(3, $publishJson['data']['published']);
        [, $after] = $this->api('GET', '/public-primary/exams/' . $this->session . '/results', 'director');
        self::assertEquals(['candidates' => 3, 'present' => 2, 'passed' => 1, 'failed' => 1, 'absent' => 1, 'success_rate' => 50.0, 'pending' => 0], $after['data']['summary']);
        [, $territory] = $this->api('GET', '/public-primary/exams/' . $this->session . '/territories/dep-1/results', 'delegate');
        self::assertEquals(50.0, $territory['data']['summary']['success_rate']);
        [$farTerritory] = $this->api('GET', '/public-primary/exams/' . $this->session . '/territories/dep-2/results', 'delegate');
        self::assertSame(403, $farTerritory->getStatusCode());
        [$national] = $this->api('GET', '/public-primary/exams/' . $this->session . '/territories/dep-2/results', 'examdir');
        self::assertSame(200, $national->getStatusCode(), 'La direction des examens atteint tout territoire.');

        // DOC-17 et DOC-16 (attestation préparée par la délégation, signée par le délégué, jamais un diplôme).
        [$statement] = $this->api('POST', '/public-primary/exams/' . $this->session . '/results/document', 'director', []);
        self::assertSame(201, $statement->getStatusCode(), (string) $statement->getBody());
        [$byDirector] = $this->api('POST', '/public-primary/exams/' . $this->session . '/attestations', 'director', []);
        self::assertContains($byDirector->getStatusCode(), [403, 404]);
        [$prepared, $preparedJson] = $this->api('POST', '/public-primary/exams/' . $this->session . '/attestations', 'delegate', []);
        self::assertSame(201, $prepared->getStatusCode(), (string) $prepared->getBody());
        self::assertCount(1, $preparedJson['data']['prepared'], 'Seul l\'admis reçoit une attestation.');
        [$signedByExamDir] = $this->api('POST', '/public-primary/exam-candidates/' . $this->candidateId('m1') . '/attestation/sign', 'examdir', []);
        self::assertContains($signedByExamDir->getStatusCode(), [403, 404]);
        [$signed, $signedJson] = $this->api('POST', '/public-primary/exam-candidates/' . $this->candidateId('m1') . '/attestation/sign', 'delegate', []);
        self::assertSame(200, $signed->getStatusCode(), (string) $signed->getBody());
        self::assertSame('signed', $signedJson['data']['attestation_state']);
        $html = $this->rows("SELECT html FROM document_templates WHERE type_code = 'pp_doc16_attestation' AND locale = 'fr'")[0]['html'];
        self::assertStringContainsString('ne tient pas lieu de diplôme', (string) $html);
        self::assertSame([], $this->rows("SELECT code FROM document_types WHERE code LIKE '%diplom%' OR label LIKE '%Diplôme%'"), 'RG-EXA-3');

        // Jurys et vacations : montants issus du paramètre (décret 2010/1738), jamais en dur.
        [$official] = $this->api('POST', '/public-primary/exams/' . $this->session . '/officials', 'delegate', ['centre_id' => $centreJson['data']['id'], 'full_name' => 'Correcteur fictif', 'function_code' => 'marker', 'half_days' => 4, 'copies_marked' => 100]);
        self::assertSame(201, $official->getStatusCode(), (string) $official->getBody());
        [, $vacations] = $this->api('GET', '/public-primary/exams/' . $this->session . '/territories/dep-1/vacations', 'delegate');
        self::assertSame(4 * 1500 + 100 * 60, $vacations['data']['total_officials']);
        self::assertSame(3 * 50, $vacations['data']['total_directors']);
        self::assertSame('official', $vacations['data']['rates_status']);

        // Dossier signé vers le secondaire (Q18).
        [$transfer, $transferJson] = $this->api('POST', '/public-primary/exam-candidates/' . $this->candidateId('m1') . '/secondary-transfer', 'director', ['target_label' => 'Lycée fictif']);
        self::assertSame(201, $transfer->getStatusCode(), (string) $transfer->getBody());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $transferJson['data']['signature']);
        self::assertSame('passed', $transferJson['data']['dossier']['exam']['outcome']);
        self::assertArrayNotHasKey('apee', $transferJson['data']['dossier']);
    }

    public function testLaSupervisionLitLaListeAvecJournalEtLEnseignantSaClasseSeulement(): void
    {
        $this->openSession();
        $this->api('POST', '/public-primary/exams/' . $this->session . '/candidates/generate', 'director', ['academic_year_id' => 'y1']);
        [$read, $json] = $this->api('GET', '/public-primary/exams/' . $this->session . '/candidates', 'inspector');
        self::assertSame(200, $read->getStatusCode(), (string) $read->getBody());
        self::assertCount(3, $json['data']);
        self::assertNotEmpty($this->journal->entries, 'Lecture nominative de la supervision journalisée (finalité exam).');
        [, $teacher] = $this->api('GET', '/public-primary/exams/' . $this->session . '/candidates', 'teacher2');
        self::assertSame([], $teacher['data'], 'Le titulaire du CE1 ne voit pas la liste du CM2.');
        [$outsider] = $this->api('GET', '/public-primary/exams', 'outsider');
        self::assertSame(404, $outsider->getStatusCode());
        [$csv] = $this->api('GET', '/public-primary/exams/' . $this->session . '/export', 'director');
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('identifiant_national', (string) $csv->getBody());
        [$doc] = $this->api('POST', '/public-primary/exams/' . $this->session . '/document', 'director', []);
        self::assertSame(201, $doc->getStatusCode(), (string) $doc->getBody());
        [$sheet] = $this->api('POST', '/public-primary/exam-candidates/' . $this->candidateId('m1') . '/sheet', 'director', []);
        self::assertSame(201, $sheet->getStatusCode(), (string) $sheet->getBody());
        [$withdrawn] = $this->api('POST', '/public-primary/exam-candidates/' . $this->candidateId('m2') . '/withdraw', 'director', []);
        self::assertSame(422, $withdrawn->getStatusCode(), 'Retrait motivé.');
    }

    private function openSession(): void
    {
        [$response, $json] = $this->api('POST', '/public-primary/exams', 'examdir', ['exam_code' => 'cep', 'year_label' => '2026-2027', 'written_on' => '2027-06-15']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['CM2'], $json['data']['level_codes']);
        $this->session = (string) $json['data']['id'];
    }

    private function registerPupil(string $id, string $first, bool $verified, string $sex): void
    {
        $pdo = $this->connections()->pdo();
        $pdo->prepare('INSERT INTO pub_pupil_registrations (id, tenant_id, establishment_id, person_id, learner_number, name_key, birth_date, sex, registered_by, registered_on) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::ulid(), 't1', 'e1', $id, 'SYN-2026-' . strtoupper($id), 'mbarga ' . strtolower($first), '2015-03-01', $sex, 'director', '2026-09-07']);
        $pdo->prepare('INSERT INTO pub_pupil_profiles (id, tenant_id, person_id, birth_act_ref, birth_act_verified, is_displaced, is_refugee, is_orphan, updated_at) VALUES (?, ?, ?, ?, ?, 0, 0, 0, ?)')
            ->execute([self::ulid(), 't1', $id, $verified ? 'SYN-ACTE-' . $id : null, $verified ? 1 : 0, '2026-09-07T08:00:00+01:00']);
    }

    /** @return array<string, string> */
    private function statesByPerson(): array
    {
        $states = [];
        foreach ($this->rows('SELECT person_id, candidate_state FROM pp_exam_candidates ORDER BY person_id') as $row) {
            $states[(string) $row['person_id']] = (string) $row['candidate_state'];
        }

        return $states;
    }

    /** @return array<string, string> */
    private function numbersByPerson(): array
    {
        $numbers = [];
        foreach ($this->rows('SELECT person_id, candidate_number FROM pp_exam_candidates ORDER BY person_id') as $row) {
            $numbers[(string) $row['person_id']] = (string) $row['candidate_number'];
        }

        return $numbers;
    }

    private function candidateId(string $personId): string
    {
        foreach ($this->rows('SELECT id, person_id FROM pp_exam_candidates') as $row) {
            if ($row['person_id'] === $personId) {
                return (string) $row['id'];
            }
        }
        self::fail('Candidat ' . $personId . ' absent.');
    }
}
