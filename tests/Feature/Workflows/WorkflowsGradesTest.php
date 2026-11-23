<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Scholaris\Modules\Apps\Higher\Grades\Application\GradesSettings;
use Scholaris\Modules\Apps\Higher\Grades\Domain\GradeLifecycle;
use Scholaris\Modules\Socle\Workflows\Application\WorkflowService;
use Tests\Support\Higher\Grades\GradesTestCase;

/**
 * Circuits « notes » (grades_lifecycle) et « correction d'une note » (grades_correction) branchés sur la résolution :
 * défaut inchangé, surcharge appliquée, et gardes qui restent quel que soit le réglage (séparation saisie / verrouillage, procès-
 * verbal signé avant publication, demandeur jamais validateur, deux validateurs distincts).
 */
final class WorkflowsGradesTest extends GradesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGrades();
    }

    /** @param array<string, array<string, mixed>> $steps */
    private function configure(string $circuit, array $steps): void
    {
        $this->service(WorkflowService::class)->override('establishment', self::T, self::E, 'higher', $circuit, ['steps' => $steps], 'Réglage de test.', 'wf-admin', null);
    }

    /** Évaluation complète et soumise par l'enseignant. */
    private function submitted(): string
    {
        $a = (string) $this->assessment()['id'];
        self::assertSame(200, $this->enter($a, ['p1' => 8.0, 'p2' => 12.5, 'p3' => 'ABI'])[0]);
        $this->ok('POST', '/higher/grade-assessments/' . $a . '/submit', null, self::TEACHER);

        return $a;
    }

    private function stateOf(string $assessmentId): string
    {
        return (string) $this->ok('GET', '/higher/grade-assessments/' . $assessmentId, null, self::CHEF)['status'];
    }

    public function testSansReglageLeCycleEstExactementCelDeLIpes(): void
    {
        $lifecycle = $this->service(GradesSettings::class)->lifecycle(self::T, self::E, null);
        self::assertSame(GradeLifecycle::ipes()->toArray(), $lifecycle->toArray());

        $a = $this->submitted();
        self::assertSame(403, $this->call('POST', '/higher/grade-assessments/' . $a . '/validate', null, self::DIRECTOR)[0], 'Le directeur ne valide pas la saisie par défaut.');
        self::assertSame(409, $this->call('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::CHEF)[0], 'Pas de verrouillage sans validation par défaut.');
        self::assertSame('validated', $this->ok('POST', '/higher/grade-assessments/' . $a . '/validate', null, self::CHEF)['status']);
    }

    public function testLaValidationDuChefDeDepartementPeutEtreSautee(): void
    {
        $this->configure('grades_lifecycle', ['validate' => ['enabled' => false]]);
        $a = $this->submitted();

        self::assertSame(409, $this->call('POST', '/higher/grade-assessments/' . $a . '/validate', null, self::CHEF)[0], 'L\'étape sautée n\'existe plus.');
        [$status, $problem] = $this->call('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::CHEF);
        self::assertSame([403, 'https://api.scholaris.cm/errors/second-factor-required'], [$status, $problem['type']], 'La confirmation récente reste exigée pour verrouiller.');
        $this->fakes->recent2fa[self::T . ':' . self::CHEF] = true;
        $locked = $this->ok('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::CHEF);
        self::assertSame(['locked', ['submitted', 'locked']], [$locked['status'], array_column($locked['status_trail'], 'to')]);
    }

    public function testLeRenvoiALEnseignantSuitLeValidateurEffectif(): void
    {
        $this->configure('grades_lifecycle', ['validate' => ['actor' => 'dir_ecole']]);
        $a = $this->submitted();

        self::assertSame(403, $this->call('POST', '/higher/grade-assessments/' . $a . '/send-back', ['reason' => 'À revoir.'], self::CHEF)[0], 'Le chef de département n\'est plus le validateur : il ne renvoie plus.');
        self::assertSame('draft', $this->ok('POST', '/higher/grade-assessments/' . $a . '/send-back', ['reason' => 'Note de p2 à vérifier.'], self::DIRECTOR)['status']);
    }

    public function testLeDirecteurDEcolePeutDevenirLeValidateurEtLeRoleEstExige(): void
    {
        $this->configure('grades_lifecycle', ['validate' => ['actor' => 'dir_ecole'], 'lock' => ['actor' => 'dir_ecole']]);
        $a = $this->submitted();

        // Le chef de département porte aussi `assessment.correction.approve` : le droit seul ne suffit pas, le rôle compte.
        [$status, $problem] = $this->call('POST', '/higher/grade-assessments/' . $a . '/validate', null, self::CHEF);
        self::assertSame(403, $status);
        self::assertStringContainsString('autre rôle', (string) $problem['detail']);
        self::assertSame('validated', $this->ok('POST', '/higher/grade-assessments/' . $a . '/validate', null, self::DIRECTOR)['status']);

        $this->fakes->recent2fa[self::T . ':' . self::CHEF] = true;
        self::assertSame(403, $this->call('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::CHEF)[0]);
        self::assertSame(403, $this->call('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::DIRECTOR)[0], 'Confirmation récente exigée du directeur.');
        $this->fakes->recent2fa[self::T . ':' . self::DIRECTOR] = true;
        self::assertSame('locked', $this->ok('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::DIRECTOR)['status']);
    }

    public function testQuiASaisiNeVerrouillePasMemeSiLeCircuitLeDesigne(): void
    {
        $this->configure('grades_lifecycle', ['lock' => ['actor' => 'dir_ecole']]);
        $this->fakes->teacher(self::T, self::DIRECTOR, 'pdir', $this->curriculum['elements']['MF'], null, self::E);
        $this->assign(self::T, self::DIRECTOR, self::E, 'assessment.enter', 'structure', $this->org['program'], 'enseignant');
        $this->assign(self::T, self::DIRECTOR, self::E, 'higher.grades.submit', 'structure', $this->org['program'], 'enseignant');
        $a = (string) $this->assessment('exam', [], 'MF', self::DIRECTOR)['id'];
        $this->enter($a, ['p1' => 8.0, 'p2' => 12.5, 'p3' => 10.0], self::DIRECTOR);
        $this->ok('POST', '/higher/grade-assessments/' . $a . '/submit', null, self::DIRECTOR);
        $this->ok('POST', '/higher/grade-assessments/' . $a . '/validate', null, self::CHEF);
        $this->fakes->recent2fa[self::T . ':' . self::DIRECTOR] = true;

        [$status, $problem] = $this->call('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::DIRECTOR);
        self::assertSame(403, $status);
        self::assertStringContainsString('Séparation des tâches', (string) $problem['detail']);
        self::assertSame('validated', $this->stateOf($a));
    }

    public function testLaPublicationPeutRevenirAuServiceDesExamensMaisLeProcesVerbalResteExige(): void
    {
        $this->configure('grades_lifecycle', ['publish' => ['actor' => 'examens']]);
        $a = (string) $this->lockedAssessment()['id'];
        $this->fakes->recent2fa[self::T . ':' . self::EXAMS] = true;
        $this->fakes->recent2fa[self::T . ':' . self::REGISTRAR] = true;

        self::assertSame(403, $this->call('POST', '/higher/grade-assessments/' . $a . '/publish', null, self::REGISTRAR)[0], 'La scolarité n\'est plus l\'acteur de la publication.');
        $this->fakes->publicationRefusal = 'Procès-verbal de délibération non signé.';
        [$status, $problem] = $this->call('POST', '/higher/grade-assessments/' . $a . '/publish', null, self::EXAMS);
        self::assertSame([409, 'Procès-verbal de délibération non signé.'], [$status, $problem['detail']], 'Le PV signé reste exigé quel que soit l\'acteur.');
        $this->fakes->publicationRefusal = null;
        self::assertSame('published', $this->ok('POST', '/higher/grade-assessments/' . $a . '/publish', null, self::EXAMS)['status']);
    }

    public function testLaConfirmationDeLaPublicationPeutEtreLevee(): void
    {
        $a = (string) $this->lockedAssessment()['id'];
        self::assertSame(403, $this->call('POST', '/higher/grade-assessments/' . $a . '/publish', null, self::REGISTRAR)[0], 'Défaut : confirmation exigée.');

        $this->configure('grades_lifecycle', ['publish' => ['recent_confirmation' => false]]);
        self::assertSame('published', $this->ok('POST', '/higher/grade-assessments/' . $a . '/publish', null, self::REGISTRAR)['status']);
    }

    public function testUneNoteVerrouilleeResteImmuableQuelQueSoitLeCircuit(): void
    {
        $this->configure('grades_lifecycle', ['validate' => ['enabled' => false]]);
        $a = (string) $this->submitted();
        $this->fakes->recent2fa[self::T . ':' . self::CHEF] = true;
        $this->ok('POST', '/higher/grade-assessments/' . $a . '/lock', null, self::CHEF);

        self::assertSame(409, $this->enter($a, ['p1' => 15.0])[0]);
        self::assertEquals(8.0, $this->ok('GET', '/higher/grade-assessments/' . $a . '/marks', null, self::CHEF)['lines'][0]['score']);
    }

    /** @return array<string, mixed> */
    private function correction(string $assessmentId): array
    {
        return $this->ok('POST', '/higher/grade-marks/' . $this->line($assessmentId, 'p1')['mark_id'] . '/changes', [
            'score' => 11.0, 'reason' => 'Erreur de report de la copie n° 12.', 'attachment_ref' => 'ged://copies/mf-12.pdf',
        ], self::TEACHER);
    }

    public function testLaSecondeValidationDUneCorrectionPeutEtreSupprimee(): void
    {
        $this->configure('grades_correction', ['school' => ['enabled' => false]]);
        $a = (string) $this->lockedAssessment()['id'];
        $correction = $this->correction($a);

        self::assertSame(['Chef de département'], $correction['steps']);
        $done = $this->ok('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::CHEF);
        self::assertSame(['approved', 1], [$done['status'], $done['approval_step']]);
        self::assertEquals(11.0, $this->line($a, 'p1')['score']);
        self::assertSame(['entry', 'correction'], array_column($this->ok('GET', '/higher/grade-marks/' . $this->line($a, 'p1')['mark_id'] . '/events', null, self::CHEF), 'kind'), 'Nouvelle version liée, la note d\'origine n\'est pas écrasée.');
    }

    public function testLeDemandeurNeValideJamaisMemeSiSaFonctionEstDesignee(): void
    {
        $this->configure('grades_correction', ['department' => ['actor' => 'dir_ecole'], 'school' => ['enabled' => false]]);
        $a = (string) $this->lockedAssessment()['id'];
        $correction = $this->correction($a);
        // L'enseignant devient directeur d'école par erreur : il reste le demandeur.
        $this->assign(self::T, self::TEACHER, self::E, 'assessment.correction.approve', 'org_unit', $this->org['school'], 'higher_dir_ecole');

        self::assertSame(403, $this->call('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::CHEF)[0], 'Le chef de département n\'est plus l\'approbateur.');
        [$status, $problem] = $this->call('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::TEACHER);
        self::assertSame(403, $status);
        self::assertStringContainsString('Séparation des tâches', (string) $problem['detail']);
        self::assertSame('approved', $this->ok('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::DIRECTOR)['status']);
    }

    public function testDeuxEtapesNeSontJamaisValideesParLaMemePersonne(): void
    {
        $this->configure('grades_correction', ['department' => ['actor' => 'dir_ecole'], 'school' => ['actor' => 'dir_ecole', 'recent_confirmation' => false]]);
        $a = (string) $this->lockedAssessment()['id'];
        $correction = $this->correction($a);

        self::assertSame(1, $this->ok('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::DIRECTOR)['approval_step']);
        [$status, $problem] = $this->call('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::DIRECTOR);
        self::assertSame(403, $status);
        self::assertStringContainsString('déjà validé', (string) $problem['detail']);
        self::assertEquals(8.0, $this->line($a, 'p1')['score'], 'La correction n\'est pas appliquée.');
    }

    public function testLaConfirmationDeLaSecondeValidationSuitLeReglage(): void
    {
        $this->configure('grades_correction', ['school' => ['recent_confirmation' => false]]);
        $a = (string) $this->lockedAssessment()['id'];
        $correction = $this->correction($a);
        $this->ok('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::CHEF);

        self::assertSame('approved', $this->ok('POST', '/higher/grade-corrections/' . $correction['id'] . '/approve', null, self::DIRECTOR)['status'], 'Sans confirmation exigée.');
    }
}
