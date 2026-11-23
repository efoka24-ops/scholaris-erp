<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Tests\Support\Assessment\AssessmentTestCase;

/**
 * S1 codes d'absence extensibles à effet déclaratif, S2 pas de saisie, S4 séparation auteur de saisie / verrouilleur.
 * Sans paramètre : comportement historique (deux codes, deux décimales, aucune séparation).
 */
final class GradeEntryExtensionsTest extends AssessmentTestCase
{
    private const IPES = [
        'assessment.absence_codes' => [
            'na' => ['effect' => 'zero', 'label' => 'Non autorisé à composer', 'aliases' => ['NA']],
            'fr' => ['effect' => 'zero', 'label' => 'Fraude', 'aliases' => ['FR']],
            'justified' => ['effect' => 'retake', 'aliases' => ['ABJ']],
        ],
        'assessment.step' => 0.25,
    ];

    public function testParDefautSeulsLesCodesDuSocleEtDeuxDecimales(): void
    {
        $this->bootAssessment();
        $this->seedClass();
        $devoir = $this->assessment($this->subject('MATH'));

        [$na] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $devoir, 'person_id' => 'p1', 'absence' => 'na']);
        self::assertSame(422, $na->getStatusCode());
        [$ok] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $devoir, 'person_id' => 'p1', 'value' => 6.3]);
        self::assertSame(201, $ok->getStatusCode(), 'Sans pas déclaré, 6,3 reste accepté.');
    }

    public function testEchelleQualitativeParametreeEstAccepteeEtExposee(): void
    {
        $this->bootAssessment(['assessment.qualitative_scale' => [
            ['code' => 'excellent', 'label_fr' => 'Excellent', 'label_en' => 'Excellent', 'sort_order' => 1],
            ['code' => 'developing', 'label_fr' => 'En développement', 'label_en' => 'Developing', 'sort_order' => 2],
            ['code' => 'emerging', 'label_fr' => 'Émergeant', 'label_en' => 'Emerging', 'sort_order' => 3],
            ['code' => 'not_yet', 'label_fr' => 'Pas encore', 'label_en' => 'Not yet', 'sort_order' => 4],
        ]]);
        $this->seedClass();
        $assessment = $this->assessment($this->subject('MATH'), 'Compétence', 1.0, 20.0, ['grading_mode' => 'qualitative']);

        [$accepted] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $assessment, 'person_id' => 'p1', 'qualitative' => 'not_yet']);
        [$unknown] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $assessment, 'person_id' => 'p2', 'qualitative' => 'acquired']);
        [, $details] = $this->api('GET', '/assessments/' . $assessment, 'reader');

        self::assertSame(201, $accepted->getStatusCode());
        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame(['excellent', 'developing', 'emerging', 'not_yet'], array_column($details['data']['qualitative_scale'], 'code'));
    }

    public function testCodesNaFrEtEffetsSurLaMoyenne(): void
    {
        $this->bootAssessment(self::IPES);
        $this->seedClass();
        $math = $this->subject('MATH');
        $cc = $this->assessment($math, 'CC');
        $exam = $this->assessment($math, 'Examen');
        foreach (['p1', 'p2', 'p3', 'p4'] as $person) {
            $this->grade($cc, $person, ['value' => 16]);
        }
        $this->grade($exam, 'p1', ['absence' => 'na']);
        $this->grade($exam, 'p2', ['absence' => 'fr']);
        $this->grade($exam, 'p3', ['absence' => 'justified']);
        $this->grade($exam, 'p4', ['value' => 12]);

        [$unknown, $json] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $exam, 'person_id' => 'p4', 'absence' => 'xyz']);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertStringContainsString('na, fr', (string) json_encode($json, JSON_UNESCAPED_UNICODE));

        $this->lock($cc);
        $this->lock($exam);
        [, $deliberation] = $this->api('POST', '/deliberations', 'jury', ['structure_id' => 'c1', 'period_id' => 'seq1', 'president_id' => 'm1', 'members_expected' => 1]);
        $averages = array_column($deliberation['data']['lines'], 'average', 'person_id');
        self::assertEquals(['p1' => 8.0, 'p2' => 8.0, 'p3' => 16.0, 'p4' => 14.0], $averages, 'NA et FR comptent zéro ; ABJ (retake) est exclue.');
    }

    public function testImportCsvDesCodesDeclares(): void
    {
        $this->bootAssessment(self::IPES);
        $this->seedClass();
        $exam = $this->assessment($this->subject('MATH'), 'Examen');

        $response = $this->upload('/grades/import?assessment_id=' . $exam, 'censor', "matricule;note\nMAT-P1;NA\nMAT-P2;FR\nMAT-P3;ABJ\nMAT-P4;12,25\n");
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $rows = array_column($this->rows('SELECT person_id, absence FROM grades'), 'absence', 'person_id');
        self::assertSame(['p1' => 'na', 'p2' => 'fr', 'p3' => 'justified', 'p4' => null], $rows);
    }

    public function testPasDeSaisieAuQuartDePointEtSurchargeParEpreuve(): void
    {
        $this->bootAssessment(self::IPES);
        $this->seedClass();
        $math = $this->subject('MATH');
        $exam = $this->assessment($math, 'Examen');

        [$refused, $json] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $exam, 'person_id' => 'p1', 'value' => 6.3]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('6,3 n\'est pas un multiple de 0,25', (string) json_encode($json, JSON_UNESCAPED_UNICODE));
        [$accepted] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $exam, 'person_id' => 'p1', 'value' => 6.25]);
        self::assertSame(201, $accepted->getStatusCode());

        $halves = $this->assessment($math, 'Oral', 1.0, 20.0, ['score_step' => 0.5]);
        [$quarter] = $this->api('POST', '/grades', 'censor', ['assessment_id' => $halves, 'person_id' => 'p1', 'value' => 6.25]);
        self::assertSame(422, $quarter->getStatusCode(), 'Le pas de l\'épreuve prime.');
        [, $shown] = $this->api('GET', '/assessments/' . $halves, 'reader');
        self::assertSame(0.5, $shown['data']['score_step']);
        self::assertSame('normal', $shown['data']['exam_session']);
    }

    public function testQuiASaisiNeVerrouillePas(): void
    {
        $this->bootAssessment(['assessment.segregate_lock' => ['locked']]);
        $this->seedClass();
        $devoir = $this->assessment($this->subject('MATH'));
        foreach (['p1', 'p2', 'p3', 'p4'] as $person) {
            $this->grade($devoir, $person, ['value' => 12]);
        }
        $this->moveTo($devoir, 'entry');

        [$refused, $json] = $this->api('POST', '/assessments/' . $devoir . '/transition', 'censor', ['to' => 'locked']);
        self::assertSame(403, $refused->getStatusCode());
        self::assertStringContainsString('une autre personne', $json['detail']);

        $this->permissions->grant('director', 'assessment.validate');
        [$ok] = $this->api('POST', '/assessments/' . $devoir . '/transition', 'director', ['to' => 'locked']);
        self::assertSame(200, $ok->getStatusCode());
    }
}
