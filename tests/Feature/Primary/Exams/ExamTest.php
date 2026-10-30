<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Exams;

use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Billing\RecordingMessenger;
use Tests\Support\Primary\PrimaryTestCase;

/** T550 : CEP / FSLC préparés par l'école (candidats en lot, doublon 409, retrait conservé, fiche, numéros, résultats officiels, export, portail). */
final class ExamTest extends PrimaryTestCase
{
    private RecordingMessenger $messenger;
    private string $examLevel;
    private string $otherLevel;

    protected function setUp(): void
    {
        $enrollments = new InMemoryEnrollments();
        $people = new InMemoryPeople();
        $this->messenger = new RecordingMessenger();
        $link = new class () implements UserPersonLink {
            public function personIdForUser(string $tenantId, string $userId): ?string
            {
                return $userId === 'parent-1' ? 'g-1' : null;
            }
        };
        $this->bootPrimary([EnrollmentDirectory::class => $enrollments, PersonDirectory::class => $people, Messenger::class => $this->messenger, UserPersonLink::class => $link], '2027-02-01');
        $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => self::YEAR]);
        $profiles = $this->call('GET', '/primary/level-profiles?sort=sort_order')[1]['data'];
        $exam = array_values(array_filter($profiles, static fn (array $p): bool => $p['is_exam_level']));
        if ($exam === []) {
            $this->ok('PATCH', '/primary/level-profiles/' . $profiles[count($profiles) - 1]['id'], ['is_exam_level' => true]);
            $exam = [$profiles[count($profiles) - 1]];
        }
        $this->examLevel = $exam[0]['level_structure_id'];
        $this->otherLevel = $profiles[0]['level_structure_id'];
        $class = $this->service(OrgUnitTree::class)->structureDescendantsOf(self::TA, self::EA, $this->examLevel)[1];
        foreach ([1, 2, 3] as $i) {
            $people->add(self::TA, "c$i", "Candidat$i", "Nom$i");
            $enrollments->add(self::TA, "en$i", "c$i", self::EA, $class, self::YEAR);
        }
        $people->add(self::TA, 'g-1', 'Parent', 'Nom1');
        $people->link(self::TA, 'g-1', 'c1');
        $this->assign(self::TA, 'parent-1', self::EA, 'self.read', Scope::ESTABLISHMENT);
    }

    public function testPreparationDuCepDeLaListeAuxResultats(): void
    {
        $catalogue = $this->ok('GET', '/primary/exam-catalogue');
        self::assertSame(['cep', 'fslc'], array_keys($catalogue['kinds']), 'Défaut : CEP et FSLC (à confirmer).');
        self::assertSame(422, $this->call('POST', '/primary/exam-sessions', ['academic_year_id' => self::YEAR, 'exam_code' => 'bepc', 'level_structure_id' => $this->examLevel])[0]);
        self::assertSame(422, $this->call('POST', '/primary/exam-sessions', ['academic_year_id' => self::YEAR, 'exam_code' => 'cep', 'level_structure_id' => $this->otherLevel])[0], 'Niveau non marqué examen.');
        $session = $this->ok('POST', '/primary/exam-sessions', ['academic_year_id' => self::YEAR, 'exam_code' => 'cep', 'level_structure_id' => $this->examLevel, 'centre_label' => 'Centre fictif', 'sub_centre_label' => 'Sous-centre fictif', 'registration_closes_on' => '2027-03-31', 'written_on' => '2027-06-16']);
        self::assertSame(409, $this->call('POST', '/primary/exam-sessions', ['academic_year_id' => self::YEAR, 'exam_code' => 'cep', 'level_structure_id' => $this->examLevel])[0]);
        $id = $session['id'];

        self::assertSame(['registered' => 3, 'existing' => 0], $this->ok('POST', "/primary/exam-sessions/$id/candidates/enrol-level"));
        self::assertSame(['registered' => 0, 'existing' => 3], $this->ok('POST', "/primary/exam-sessions/$id/candidates/enrol-level"));
        self::assertSame(409, $this->call('POST', "/primary/exam-sessions/$id/candidates", ['person_id' => 'c1'])[0], 'Doublon.');
        $candidates = array_column($this->ok('GET', "/primary/exam-sessions/$id/candidates")['items'], null, 'person_id');
        $c3 = $candidates['c3']['id'];
        self::assertSame('withdrawn', $this->ok('POST', "/primary/exam-candidates/$c3/withdraw", ['reason' => 'Départ de l\'école'])['registration_status']);
        self::assertCount(3, $this->ok('GET', "/primary/exam-sessions/$id/candidates")['items'], 'Retrait conservé.');

        $c1 = $candidates['c1']['id'];
        self::assertSame(422, $this->call('PATCH', "/primary/exam-candidates/$c1", ['fields' => ['champ_inconnu' => 'x']])[0]);
        $this->ok('PATCH', "/primary/exam-candidates/$c1", ['fields' => ['birth_place' => 'Garoua']]);
        $sheet = $this->ok('GET', "/primary/exam-candidates/$c1/sheet");
        self::assertSame([], $sheet['missing_required']);
        self::assertSame('Sous-centre fictif', $sheet['session']['sub_centre_label']);
        self::assertSame(['birth_place'], $this->ok('GET', '/primary/exam-candidates/' . $candidates['c2']['id'] . '/sheet')['missing_required']);

        self::assertSame(['assigned' => 2], $this->ok('POST', "/primary/exam-sessions/$id/numbers"));
        $numbers = array_column($this->ok('GET', "/primary/exam-sessions/$id/candidates")['items'], 'candidate_number', 'person_id');
        self::assertSame('CEP-0001', $numbers['c1']);
        self::assertSame(409, $this->call('PATCH', '/primary/exam-candidates/' . $candidates['c2']['id'], ['candidate_number' => 'CEP-0001'])[0]);

        self::assertSame(409, $this->call('PUT', "/primary/exam-sessions/$id/results", ['entries' => []])[0], 'Résultats après clôture des inscriptions.');
        $this->ok('POST', "/primary/exam-sessions/$id/status", ['status' => 'open']);
        $this->ok('POST', "/primary/exam-sessions/$id/status", ['status' => 'closed']);
        self::assertNull($this->ok('GET', '/primary/family/children/c1/exams', null, 'parent-1')['items'][0]['outcome'], 'Résultat invisible avant publication.');
        $import = $this->ok('POST', "/primary/exam-sessions/$id/results/import", ['csv' => "numero;resultat;mention\nCEP-0001;Admis;Assez bien\nCEP-0002;inconnu\nCEP-9999;admis"]);
        self::assertSame(1, $import['recorded']);
        self::assertCount(2, $import['rejected'], 'Lignes invalides rejetées avec motif, les autres acceptées.');
        $this->ok('PUT', "/primary/exam-sessions/$id/results", ['entries' => [['candidate_id' => $candidates['c2']['id'], 'outcome' => 'failed']]]);
        $stats = $this->ok('GET', "/primary/exam-sessions/$id")['stats'];
        self::assertEquals([1, 1, 1, 50.0], [$stats['passed'], $stats['failed'], $stats['withdrawn'], $stats['pass_rate']]);

        $published = $this->ok('POST', "/primary/exam-sessions/$id/results/publish");
        self::assertSame(1, $published['notified']);
        self::assertCount(1, $this->messenger->of('pri.not09.exam_results'));
        $family = $this->ok('GET', '/primary/family/children/c1/exams', null, 'parent-1')['items'];
        self::assertSame(['passed', 'Assez bien'], [$family[0]['outcome'], $family[0]['outcome_detail']]);

        $response = $this->raw('GET', "/primary/exam-sessions/$id/export");
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('CEP-0001', (string) $response->getBody());
        self::assertSame(422, $this->call('POST', "/primary/exam-sessions/$id/invoices")[0], 'Aucun frais paramétré.');

        $this->assign(self::TA, 'maitre', self::EA, 'primary.exam.read', Scope::CLASS_GROUP, 'x');
        self::assertSame(403, $this->call('POST', "/primary/exam-sessions/$id/numbers", null, 'maitre')[0]);
        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->call('GET', "/primary/exam-sessions/$id", null, 'directeur-b', self::TB, self::EB)[0]);
        self::assertSame(404, $this->call('PATCH', "/primary/exam-candidates/$c1", ['candidate_number' => 'X'], 'directeur-b', self::TB, self::EB)[0]);
    }
}
