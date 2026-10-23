<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Tests\Support\Assessment\AssessmentTestCase;

/** Cycle de vie des notes paramétrable par application, règles versionnées par année (T328, T120). */
final class ConfigurableLifecycleTest extends AssessmentTestCase
{
    /** Cycle de l'IPES : brouillon, soumise, validée, verrouillée, publiée. */
    private const IPES = [
        'states' => ['draft', 'submitted', 'validated', 'locked', 'published'],
        'editable' => ['draft'],
        'published' => 'published',
        'transitions' => [
            ['from' => 'draft', 'to' => 'submitted', 'permission' => 'assessment.enter'],
            ['from' => 'submitted', 'to' => 'validated', 'permission' => 'assessment.validate'],
            ['from' => 'validated', 'to' => 'locked', 'permission' => 'assessment.publish', 'require_recent_2fa' => true],
            ['from' => 'locked', 'to' => 'published', 'permission' => 'assessment.publish'],
        ],
    ];

    protected function setUp(): void
    {
        $this->bootAssessment(['assessment.lifecycle' => self::IPES]);
        $this->seedClass();
    }

    public function testLApplicationDeclareSesEtatsSesRolesEtSa2faRecente(): void
    {
        $assessment = $this->assessment($this->subject(), 'Examen');
        foreach (['p1', 'p2', 'p3', 'p4'] as $person) {
            $this->grade($assessment, $person, ['value' => 12]);
        }

        // Le défaut du socle (draft, entry...) est remplacé : « entry » n'existe pas dans l'IPES.
        [$unknown] = $this->api('POST', '/assessments/' . $assessment . '/transition', 'censor', ['to' => 'entry']);
        self::assertSame(422, $unknown->getStatusCode());

        [$denied] = $this->api('POST', '/assessments/' . $assessment . '/transition', 'teacher', ['to' => 'submitted']);
        self::assertSame(200, $denied->getStatusCode(), 'L\'enseignant soumet.');
        [$tooEarly] = $this->api('POST', '/assessments/' . $assessment . '/transition', 'teacher', ['to' => 'validated']);
        self::assertSame(403, $tooEarly->getStatusCode(), 'La validation relève du chef de département.');

        // Soumise = plus modifiable (seul « draft » l'est).
        [$locked] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $assessment, 'person_id' => 'p1', 'value' => 13]);
        self::assertSame(409, $locked->getStatusCode());

        $this->moveTo($assessment, 'validated');
        [$noFactor, $problem] = $this->api('POST', '/assessments/' . $assessment . '/transition', 'censor', ['to' => 'locked']);
        self::assertSame(403, $noFactor->getStatusCode());
        self::assertStringContainsString('double authentification récente', $problem['detail']);

        $this->secondFactor->recent = true;
        $this->moveTo($assessment, 'locked');
        $this->moveTo($assessment, 'published');
        self::assertSame('published', $this->rows('SELECT status FROM assessments')[0]['status']);
    }

    public function testUneDeclarationIncoherenteRetombeSurLeCycleParDefaut(): void
    {
        $this->settings->values['assessment.lifecycle'] = ['states' => ['a'], 'editable' => ['a'], 'published' => 'a', 'transitions' => []];
        $assessment = $this->assessment($this->subject(), 'Examen');

        [, $json] = $this->api('GET', '/assessments/' . $assessment, 'reader');

        self::assertSame(['draft', 'entry', 'locked', 'published'], $json['data']['lifecycle']['states']);
        self::assertSame('draft', $json['data']['status']);
    }

    public function testValiderLaGrilleFaitPasserToutesLesEpreuvesDeLaClasse(): void
    {
        $this->settings->values = [];
        $subject = $this->subject();
        $a = $this->assessment($subject, 'Interro');
        $b = $this->assessment($subject, 'Devoir');
        foreach ([$a, $b] as $assessment) {
            foreach (['p1', 'p2', 'p3', 'p4'] as $person) {
                $this->grade($assessment, $person, ['value' => 11]);
            }
        }
        $this->api('POST', '/grade-sheets/transition', 'censor', ['structure_id' => 'c1', 'period_id' => 'seq1', 'to' => 'entry']);

        $this->grade($a, 'p1', ['value' => 12]);
        [$response, $json] = $this->api('POST', '/grade-sheets/transition', 'censor', ['structure_id' => 'c1', 'period_id' => 'seq1', 'to' => 'locked']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['locked', 'locked'], array_column($json['data'], 'status'));
    }

    public function testReglesVersionneesParAnneeEtAppliqueesAuCalcul(): void
    {
        $this->settings->values = [];
        $assessment = $this->assessment($this->subject(), 'Examen');
        $this->grade($assessment, 'p1', ['value' => 14]);
        $this->grade($assessment, 'p2', ['absence' => 'unjustified']);

        [$first, $v1] = $this->api('POST', '/calculation-rules', 'censor', ['academic_year_id' => 'ay1', 'label' => 'Règles 2026-2027 v1', 'definition' => ['rounding' => 1]]);
        [$second, $v2] = $this->api('POST', '/calculation-rules', 'censor', ['academic_year_id' => 'ay1', 'label' => 'Règles 2026-2027 v2', 'definition' => ['unjustified_absence' => 'excluded', 'pass_mark' => 9]]);
        [$otherYear, $y2] = $this->api('POST', '/calculation-rules', 'censor', ['academic_year_id' => 'ay2', 'label' => 'Règles 2027-2028', 'definition' => ['rounding' => 0]]);

        self::assertSame([201, 201, 201], [$first->getStatusCode(), $second->getStatusCode(), $otherYear->getStatusCode()]);
        self::assertSame([1, 2, 1], [$v1['data']['version'], $v2['data']['version'], $y2['data']['version']]);

        [, $stats] = $this->api('GET', '/sequence-statistics?structure_id=c1&period_id=seq1', 'reader');
        self::assertSame(2, $stats['data']['rules_version'], 'La dernière version de l\'année s\'applique.');
        self::assertEquals(9.0, $stats['data']['pass_mark']);

        [, $grid] = $this->api('GET', '/grade-sheets?structure_id=c1&period_id=seq1', 'reader');
        $rows = array_column($grid['data']['rows'], null, 'person_id');
        self::assertEquals(14.0, $rows['p1']['average']);
        self::assertNull($rows['p2']['average'], 'Absence non justifiée exclue par la version 2 : aucune note ne compte.');

        [$show, $detail] = $this->api('GET', '/calculation-rules/' . $v1['data']['id'], 'reader');
        self::assertSame(200, $show->getStatusCode());
        self::assertSame(1, $detail['data']['effective']['rounding']);

        [, $list] = $this->api('GET', '/calculation-rules?filter[academic_year_id]=ay1', 'reader');
        self::assertCount(2, $list['data']);
    }

    public function testUneRegleIncoherenteEstRefuseeEtSeulCeluiQuiValideLesPublie(): void
    {
        [$bad, $json] = $this->api('POST', '/calculation-rules', 'censor', ['academic_year_id' => 'ay1', 'label' => 'Mauvaise', 'definition' => ['pass_mark' => 99]]);
        [$unknown] = $this->api('POST', '/calculation-rules', 'censor', ['academic_year_id' => 'ay1', 'label' => 'Inconnue', 'definition' => ['regle_inventee' => 1]]);
        [$denied] = $this->api('POST', '/calculation-rules', 'teacher', ['academic_year_id' => 'ay1', 'label' => 'Intrus', 'definition' => []]);

        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('pass_mark', $json['errors']['definition'][0]);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame([], $this->rows('SELECT id FROM calculation_rules'));
    }

    public function testLesReglesDeBaseViennentDesParametresDeLEtablissement(): void
    {
        $this->settings->values = ['assessment.pass_mark' => 8, 'assessment.mentions' => [['min' => 0, 'label' => 'Acquis'], ['min' => 15, 'label' => 'Excellent']], 'assessment.scale' => 99999];
        $assessment = $this->assessment($this->subject(), 'Examen');
        $this->grade($assessment, 'p1', ['value' => 16]);

        [, $grid] = $this->api('GET', '/grade-sheets?structure_id=c1&period_id=seq1', 'reader');
        [, $stats] = $this->api('GET', '/sequence-statistics?structure_id=c1&period_id=seq1', 'reader');

        self::assertSame('Excellent', array_column($grid['data']['rows'], null, 'person_id')['p1']['appraisal']);
        self::assertEquals([20.0, 8.0], [$stats['data']['scale'], $stats['data']['pass_mark']], 'Barème aberrant ignoré, seuil valide retenu.');
    }
}
