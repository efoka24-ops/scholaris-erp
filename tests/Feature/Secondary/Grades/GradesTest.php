<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Grades;

use Scholaris\Modules\Apps\Secondary\Grades\Domain\Defaults;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;
use Tests\Support\Assessment\FixedActors;
use Tests\Support\Assessment\InMemoryEnrollments;
use Tests\Support\Assessment\InMemoryPeople;
use Tests\Support\Assessment\RecordingMessenger;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Lot E2-C (T868 à T870, TST-09) : préréglage des paramètres de notes sans écrasement, grille de l'enseignant (coefficients de
 * Setup, matières saisissables), contrôle d'affectation journalisé, fraude, épreuves communes, NOT-04, isolation. Données fictives.
 */
final class GradesTest extends SecondaryTestCase
{
    private const YEAR = 'annee-2026';
    private const MANAGE = ['secondary.profile.manage', 'secondary.settings.manage', 'secondary.profile.read'];

    private FixedActors $actors;
    private InMemoryPeople $people;
    private InMemoryEnrollments $enrollments;
    private RecordingMessenger $messenger;
    /** @var array<string, list<array{establishment_id: string, structure_id: ?string, subject_label: ?string, session_kind: string}>> */
    private array $assignments = [];
    private string $school = '';
    private string $classA = '';
    private string $classB = '';

    protected function setUp(): void
    {
        $this->actors = new FixedActors();
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->messenger = new RecordingMessenger();
        $assignments = &$this->assignments;
        $workload = new class ($assignments) implements TeacherWorkload {
            /** @param array<string, list<array{establishment_id: string, structure_id: ?string, subject_label: ?string, session_kind: string}>> $assignments */
            public function __construct(private array &$assignments)
            {
            }

            public function assignmentsOf(string $tenantId, string $personId): array
            {
                return $this->assignments[$personId] ?? [];
            }

            public function hoursOf(string $tenantId, string $establishmentId, string $personId, string $month): array
            {
                return ['month' => $month, 'declared_minutes' => 0, 'validated_minutes' => 0, 'rejected_minutes' => 0];
            }
        };
        $this->bootSecondary([
            ActorDirectory::class => $this->actors, TeacherWorkload::class => $workload, PersonDirectory::class => $this->people,
            EnrollmentDirectory::class => $this->enrollments, Messenger::class => $this->messenger,
        ]);

        $this->school = $this->factory->establishment(self::TA, 'leg');
        $this->grant(self::TA, self::ADMIN, $this->school, self::MANAGE);
        $this->ok('POST', '/secondary/setup/profile/open', ['reason' => 'Ouverture de test'], self::ADMIN, self::TA, $this->school);
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $this->school), null, 'code');
        $this->classA = (string) $this->ok('POST', '/secondary/setup/classes', ['label' => '6e A', 'level_id' => $levels['6E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $this->school)['structure_id'];
        $this->classB = (string) $this->ok('POST', '/secondary/setup/classes', ['label' => '6e B', 'level_id' => $levels['6E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $this->school)['structure_id'];
        foreach ([['MATH', 'Mathématiques', 4], ['FRAN', 'Français', 3]] as [$code, $label, $coefficient]) {
            $subject = $this->ok('POST', '/secondary/setup/subjects', ['code' => $code, 'label' => $label], self::ADMIN, self::TA, $this->school);
            $this->ok('POST', '/secondary/setup/coefficients', [
                'academic_year_id' => self::YEAR, 'level_code' => '6E', 'subject_ref' => $subject['id'], 'coefficient' => $coefficient, 'weekly_hours' => 4,
                'subject_role' => 'official', 'origin' => 'example', 'valid_from' => '2026-09-01', 'reason' => 'Valeur d\'exemple',
            ], self::ADMIN, self::TA, $this->school);
        }
        foreach (['el-1' => ['Aïcha', 'Mballa'], 'el-2' => ['Brice', 'Ndzi']] as $id => [$first, $last]) {
            $this->people->add(self::TA, $id, $first, $last);
            $this->enrollments->add(self::TA, $id, $this->school, $this->classA);
        }

        // Enseignant affecté en 6e A pour les mathématiques ; enseignant sans affectation ; enseignant au rôle limité à la 6e B.
        $this->grant(self::TA, 'prof-a', $this->school, ['secondary.grades.enter', 'secondary.grades.read']);
        $this->actors->persons['prof-a'] = 'pers-a';
        $this->assignments['pers-a'] = [['establishment_id' => $this->school, 'structure_id' => $this->classA, 'subject_label' => 'Mathematiques', 'session_kind' => 'course']];
        $this->grant(self::TA, 'prof-b', $this->school, ['secondary.grades.enter']);
        $this->actors->persons['prof-b'] = 'pers-b';
        $this->grant(self::TA, 'prof-c', $this->school, ['secondary.grades.enter'], Scope::CLASS_GROUP, $this->classB);
        $this->actors->persons['prof-c'] = 'pers-c';
        $this->grant(self::TA, 'censeur', $this->school, ['secondary.grades.validate', 'secondary.grades.lock', 'secondary.grades.read']);
    }

    /** @return array<string, array<string, mixed>> */
    private function subjects(string $user): array
    {
        $sheet = $this->ok('GET', '/secondary/grades/sheet?class_id=' . $this->classA . '&period_id=seq-1', null, $user, self::TA, $this->school);

        return array_column($sheet['subjects'], null, 'code');
    }

    public function testPrereglageDesParametresSansEcraserLaValeurDeLEtablissement(): void
    {
        $before = $this->ok('GET', '/secondary/grades/settings', null, self::ADMIN, self::TA, $this->school);
        self::assertNull($before['values']['assessment.step'], 'Rien n\'est posé d\'office.');
        self::assertFalse($before['offline_entry']);

        self::assertTrue($this->service(SettingsWriter::class)->presetIfAbsent(self::TA, $this->school, 'assessment.step', 0.5, self::ADMIN));
        $data = $this->ok('POST', '/secondary/grades/settings/preset', [], self::ADMIN, self::TA, $this->school);

        self::assertContains('assessment.lifecycle', $data['applied']);
        self::assertSame(['assessment.step'], $data['kept'], 'La valeur de l\'établissement est conservée.');
        self::assertEquals(0.5, $data['step']);
        self::assertSame(Defaults::LIFECYCLE['states'], $data['values']['assessment.lifecycle']['states']);
        self::assertTrue($data['lock_requires_second_factor']);
        self::assertSame(['fraud', 'exempt', 'replacement'], array_column($data['absence_codes'], 'code'));
        self::assertSame([], $data['letter_scale'], 'Q7 : lettres désactivées.');
        self::assertContains('secondary.grades.settings.preset', $this->audit->actions());
        self::assertSame(403, $this->call('POST', '/secondary/grades/settings/preset', [], 'censeur', self::TA, $this->school)[0]);
    }

    public function testGrilleDeLEnseignantAvecCoefficientsEtMatieresSaisissables(): void
    {
        $sheet = $this->ok('GET', '/secondary/grades/sheet?class_id=' . $this->classA . '&period_id=seq-1', null, 'prof-a', self::TA, $this->school);
        self::assertSame('6e A', $sheet['class']['label']);
        self::assertSame(['Mballa Aïcha', 'Ndzi Brice'], array_column($sheet['learners'], 'name'));
        $subjects = array_column($sheet['subjects'], null, 'code');
        self::assertEquals(4, $subjects['MATH']['coefficient'], 'Coefficient versionné de Setup.');
        self::assertTrue($subjects['MATH']['can_enter']);
        self::assertFalse($subjects['FRAN']['can_enter'], 'Matière non affectée.');
        self::assertTrue($this->subjects('censeur')['FRAN']['can_enter'], 'Le censeur saisit pour toute la classe.');

        $check = $this->ok('POST', '/secondary/grades/entry-checks', ['class_id' => $this->classA, 'subject_id' => $subjects['MATH']['subject_id']], 'prof-a', self::TA, $this->school);
        self::assertTrue($check['allowed']);
        self::assertSame('teacher', $check['role']);
        self::assertSame(422, $this->call('POST', '/secondary/grades/entry-checks', ['class_id' => $this->classA, 'subject_id' => 'matiere-inconnue'], 'censeur', self::TA, $this->school)[0]);

        $statement = $this->ok('GET', '/secondary/grades/statement?class_id=' . $this->classA . '&period_id=seq-1', null, 'prof-a', self::TA, $this->school);
        self::assertFalse($statement['letters_enabled']);
        self::assertSame('6e A', $statement['class']['label']);
    }

    public function testTst09SaisieHorsAffectationRefuseeEtJournalisee(): void
    {
        $math = (string) $this->subjects('censeur')['MATH']['subject_id'];
        $french = (string) $this->subjects('censeur')['FRAN']['subject_id'];
        foreach ([['prof-b', $math, 'class_not_assigned'], ['prof-c', $math, 'class_out_of_scope'], ['prof-a', $french, 'subject_not_assigned']] as [$user, $subject, $reason]) {
            $count = count($this->audit->entries);
            [$status, $problem] = $this->call('POST', '/secondary/grades/entry-checks', ['class_id' => $this->classA, 'subject_id' => $subject], $user, self::TA, $this->school);
            self::assertSame(403, $status, $user);
            self::assertStringContainsString('tentative est enregistrée', (string) $problem['detail']);
            self::assertCount($count + 1, $this->audit->entries);
            $last = $this->audit->entries[$count];
            self::assertSame(['secondary.grades.denied', $user, $this->classA], [$last['action'], $last['actor'], $last['id']], $reason);
        }
        [$status] = $this->call('POST', '/secondary/grades/fraud-reports', ['class_id' => $this->classA, 'subject_id' => $math, 'assessment_id' => 'ep-1', 'person_id' => 'el-1'], 'prof-b', self::TA, $this->school);
        self::assertSame(403, $status, 'Saisine refusée hors affectation.');
    }

    public function testFraudeSaisineTraiteeParLeSurveillantGeneral(): void
    {
        $math = (string) $this->subjects('prof-a')['MATH']['subject_id'];
        $report = $this->ok('POST', '/secondary/grades/fraud-reports', ['class_id' => $this->classA, 'subject_id' => $math, 'assessment_id' => 'ep-1', 'person_id' => 'el-2', 'note' => 'Antisèche'], 'prof-a', self::TA, $this->school);
        self::assertSame('open', $report['status']);
        self::assertSame(409, $this->call('POST', '/secondary/grades/fraud-reports', ['class_id' => $this->classA, 'subject_id' => $math, 'assessment_id' => 'ep-1', 'person_id' => 'el-2'], 'prof-a', self::TA, $this->school)[0], 'Une saisine par note.');

        $this->grant(self::TA, 'sg', $this->school, ['secondary.discipline.manage']);
        self::assertCount(1, $this->ok('GET', '/secondary/grades/fraud-reports?filter[status]=open', null, 'sg', self::TA, $this->school));
        self::assertSame(403, $this->call('POST', '/secondary/grades/fraud-reports/' . $report['id'] . '/handle', ['outcome' => 'Vu'], 'prof-a', self::TA, $this->school)[0]);
        $handled = $this->ok('POST', '/secondary/grades/fraud-reports/' . $report['id'] . '/handle', ['outcome' => 'Élève et parent reçus'], 'sg', self::TA, $this->school);
        self::assertSame(['handled', 'sg'], [$handled['status'], $handled['handled_by']]);
        self::assertSame(409, $this->call('POST', '/secondary/grades/fraud-reports/' . $report['id'] . '/handle', ['outcome' => 'Encore'], 'sg', self::TA, $this->school)[0]);
    }

    public function testEpreuvesCommunesEtNotificationDeNotePubliee(): void
    {
        $math = (string) $this->subjects('censeur')['MATH']['subject_id'];
        $test = $this->ok('POST', '/secondary/grades/common-tests', ['academic_year_id' => self::YEAR, 'level_code' => '6E', 'subject_id' => $math, 'period_id' => 'seq-1', 'title' => 'Composition commune de mathématiques'], 'censeur', self::TA, $this->school);
        self::assertSame('planned', $test['status']);
        $test = $this->ok('PATCH', '/secondary/grades/common-tests/' . $test['id'], ['assessment_ids' => ['ep-a', 'ep-b'], 'status' => 'held'], 'censeur', self::TA, $this->school);
        self::assertSame(['ep-a', 'ep-b'], $test['assessment_ids']);
        self::assertSame(403, $this->call('POST', '/secondary/grades/common-tests', ['academic_year_id' => self::YEAR, 'level_code' => '6E', 'subject_id' => $math, 'period_id' => 'seq-1', 'title' => 'X'], 'prof-a', self::TA, $this->school)[0]);
        self::assertCount(1, $this->ok('GET', '/secondary/grades/common-tests?filter[level_code]=6E', null, 'prof-a', self::TA, $this->school));

        $body = ['class_id' => $this->classA, 'assessment_id' => 'ep-a', 'subject_label' => 'Mathématiques', 'label' => 'Devoir 1'];
        $first = $this->ok('POST', '/secondary/grades/publications', $body, 'censeur', self::TA, $this->school);
        self::assertSame(2, $first['notified'], 'Les deux élèves (la doublure de PersonDirectory ne rend aucun garant).');
        self::assertSame('sec.not04.grade_published', $this->messenger->sent[0]['template']);
        self::assertSame(0, $this->ok('POST', '/secondary/grades/publications', $body, 'censeur', self::TA, $this->school)['notified'], 'Jamais deux fois.');
        self::assertSame(403, $this->call('POST', '/secondary/grades/publications', $body, 'prof-a', self::TA, $this->school)[0]);

        $late = $this->ok('POST', '/secondary/grades/late-reminders', ['class_id' => $this->classA, 'period_id' => 'seq-1', 'due_on' => '2026-10-30', 'subject_ids' => [$math]], 'censeur', self::TA, $this->school);
        self::assertSame([$math], $late['without_teacher'], 'Aucun créneau d\'emploi du temps : signalé, rien envoyé.');
    }

    public function testIsolationEntreClients(): void
    {
        $other = $this->factory->establishment(self::TB, 'leg');
        $this->grant(self::TB, 'intrus', $other, ['secondary.grades.validate', 'secondary.grades.read']);
        self::assertSame(404, $this->call('GET', '/secondary/grades/sheet?class_id=' . $this->classA . '&period_id=seq-1', null, 'intrus', self::TB, $other)[0]);
        $math = (string) $this->subjects('prof-a')['MATH']['subject_id'];
        $report = $this->ok('POST', '/secondary/grades/fraud-reports', ['class_id' => $this->classA, 'subject_id' => $math, 'assessment_id' => 'ep-9', 'person_id' => 'el-1'], 'prof-a', self::TA, $this->school);
        $this->grant(self::TB, 'intrus', $other, ['secondary.discipline.manage']);
        self::assertSame([], $this->ok('GET', '/secondary/grades/fraud-reports', null, 'intrus', self::TB, $other));
        self::assertSame(404, $this->call('POST', '/secondary/grades/fraud-reports/' . $report['id'] . '/handle', ['outcome' => 'x'], 'intrus', self::TB, $other)[0]);
    }
}
