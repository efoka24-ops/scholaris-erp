<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use Scholaris\Modules\Apps\Secondary\Grades\Domain\Defaults;
use Tests\Support\Assessment\AssessmentTestCase;

/**
 * TST-05 (T948) et cycle des notes du secondaire (T868, T869) de bout en bout sur le socle Assessment réel (SQLite migrée), avec le
 * préréglage d'exemple du secondaire (Grades/Domain/Defaults) : pas de saisie au quart de point, code « fraud » à zéro, cycle
 * brouillon → soumise → validée → verrouillée (deuxième authentification), séparation saisie et verrouillage, correction en deux
 * validations (censeur puis chef d'établissement). Le conseil (et donc le bulletin) est refusé tant qu'une épreuve n'est pas
 * verrouillée.
 */
final class UnlockedReportCardTest extends AssessmentTestCase
{
    private string $assessment = '';
    private string $council = '';

    protected function setUp(): void
    {
        $this->bootAssessment(Defaults::settings());
        $this->seedClass();
        $this->permissions->grant('teacher', 'secondary.grades.enter');
        $this->permissions->grant('censor', 'secondary.grades.validate', 'secondary.grades.lock');
        $this->permissions->grant('director', 'secondary.reportcard.validate');
        $this->assessment = $this->assessment($this->subject('MATH', 'subject', 4.0), 'Devoir de mathématiques');
    }

    private function enterAll(): void
    {
        foreach (['p1' => 12.25, 'p2' => 15.5, 'p3' => 9.75] as $person => $value) {
            $this->grade($this->assessment, $person, ['value' => $value], 'teacher');
        }
        $this->grade($this->assessment, 'p4', ['absence' => 'fraud'], 'teacher');
    }

    private function move(string $user, string $to): int
    {
        [$response] = $this->api('POST', '/assessments/' . $this->assessment . '/transition', $user, ['to' => $to]);

        return $response->getStatusCode();
    }

    private function validateCouncil(): int
    {
        if ($this->council === '') {
            [$response, $json] = $this->api('POST', '/class-councils', 'council', ['structure_id' => 'c1', 'period_id' => 'seq1', 'period_label' => 'Séquence 1']);
            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
            $this->council = (string) $json['data']['id'];
        }
        [$validated] = $this->api('POST', '/class-councils/' . $this->council . '/validate', 'council');

        return $validated->getStatusCode();
    }

    public function testPasDeSaisieEtFraude(): void
    {
        [$response, $json] = $this->api('POST', '/grades', 'teacher', ['assessment_id' => $this->assessment, 'person_id' => 'p1', 'value' => 12.3]);
        self::assertSame(422, $response->getStatusCode(), 'RG-NOT-1 : note hors pas.');
        self::assertStringContainsString('0,25', (string) json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->enterAll();
        self::assertSame('fraud', $this->rows("SELECT absence FROM grades WHERE person_id = 'p4'")[0]['absence']);
    }

    public function testTst05ConseilEtBulletinRefusesTantQueLaNoteNEstPasVerrouillee(): void
    {
        $this->enterAll();
        self::assertSame(409, $this->validateCouncil(), 'Brouillon.');
        self::assertSame(200, $this->move('teacher', 'submitted'));
        self::assertSame(403, $this->move('teacher', 'validated'), 'L\'enseignant ne valide pas.');
        self::assertSame(200, $this->move('censor', 'validated'));
        self::assertSame(409, $this->validateCouncil(), 'Validée mais non verrouillée : refus (RG-BUL-1).');

        self::assertSame(403, $this->move('censor', 'locked'), 'Verrouillage sans deuxième authentification récente.');
        $this->secondFactor->recent = true;
        $this->permissions->grant('teacher', 'secondary.grades.lock');
        self::assertSame(403, $this->move('teacher', 'locked'), 'Qui a saisi ne verrouille pas (assessment.segregate_lock).');
        self::assertSame(200, $this->move('censor', 'locked'));
        self::assertSame(200, $this->validateCouncil(), 'Toutes les notes verrouillées : le conseil est validé, les bulletins mis en file.');

        [$response] = $this->api('POST', '/grades', 'teacher', ['assessment_id' => $this->assessment, 'person_id' => 'p1', 'value' => 18]);
        self::assertSame(409, $response->getStatusCode(), 'RG-NOT-4 : une note verrouillée n\'est jamais écrasée.');
    }

    public function testCorrectionValideeParLeCenseurPuisLeChef(): void
    {
        $this->enterAll();
        $this->move('teacher', 'submitted');
        $this->move('censor', 'validated');
        $this->secondFactor->recent = true;
        $this->move('censor', 'locked');
        $grade = $this->rows("SELECT id FROM grades WHERE person_id = 'p1'")[0]['id'];

        [$response, $json] = $this->api('POST', '/grade-corrections', 'teacher', ['grade_id' => $grade, 'value' => 13.5, 'reason' => 'Erreur de report sur la copie']);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        $correction = (string) $json['data']['id'];
        [$denied] = $this->api('POST', '/grade-corrections/' . $correction . '/approve', 'director');
        self::assertSame(403, $denied->getStatusCode(), 'La première étape revient au censeur.');
        [$first] = $this->api('POST', '/grade-corrections/' . $correction . '/approve', 'censor');
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertEquals(12.25, $this->rows("SELECT score FROM grades WHERE person_id = 'p1'")[0]['score'], 'Inchangée tant que le chef n\'a pas validé.');
        [$second] = $this->api('POST', '/grade-corrections/' . $correction . '/approve', 'director');
        self::assertSame(200, $second->getStatusCode(), (string) $second->getBody());
        self::assertEquals(13.5, $this->rows("SELECT score FROM grades WHERE person_id = 'p1'")[0]['score']);
        self::assertGreaterThanOrEqual(2, count($this->rows("SELECT id FROM grade_events WHERE grade_id = '" . $grade . "'")), 'Ancienne valeur conservée dans l\'historique.');
    }
}
