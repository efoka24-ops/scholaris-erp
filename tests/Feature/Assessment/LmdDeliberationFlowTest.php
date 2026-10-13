<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleCalculator;
use Tests\Support\Assessment\AssessmentTestCase;
use Tests\Support\Assessment\LmdTestStrategy;

/**
 * Parcours complet de type IPES par l'API : règles rattachées à la structure (S5) nommant une stratégie enregistrée
 * (S6), sessions normale et de rattrapage (S7), gardes de délibération (S8 : notes verrouillées, 2FA, signature du
 * président, empreinte SHA-256, nouveau PV à la réouverture, alerte DG) et étiquettes de décision (S9).
 * Données : Marie N. (p1), scénario B du CDC (éliminatoire 8, rattrapage à 11).
 */
final class LmdDeliberationFlowTest extends AssessmentTestCase
{
    /** EC : unité, intitulé, crédits, CC, examen. */
    private const MARIE = [
        ['UE1', 'Comptabilité générale', 4, 12, 9], ['UE1', 'TD de comptabilité', 2, 14, 11],
        ['UE2', 'Mathématiques financières', 6, 8, 6.5],
        ['UE3', 'Microéconomie', 3, 13, 12], ['UE3', 'Macroéconomie', 3, 11, 10.5],
        ['UE4', 'Droit civil', 3, 10, 13], ['UE4', 'Institutions administratives', 3, 15, 14],
        ['UE5', 'Anglais', 3, 14, 15], ['UE5', 'Bureautique', 3, 16, 15.5],
    ];

    /** @var list<string> */
    private array $normalAssessments = [];
    private string $mathsSubject = '';
    private string $mathsElement = '';

    protected function setUp(): void
    {
        $this->bootAssessment([
            'assessment.deliberation.require_locked_grades' => true,
            'assessment.deliberation.require_2fa' => true,
            'assessment.deliberation.signatures_required' => 'president',
            'assessment.deliberation.verify_fingerprint' => true,
            'assessment.deliberation.freeze_results' => true,
            'assessment.deliberation.reopen_permission' => 'assessment.reopen',
            'assessment.deliberation.reopen_alert' => ['template' => 'higher.deliberation_reopened', 'person_ids' => ['dg']],
        ], ['assessment.rule_strategies.test' => [LmdTestStrategy::class]]);
        $this->seedClass();
        $this->access->grant('academic', 'e1');
        $this->permissions->grant('academic', 'assessment.read', 'assessment.reopen');

        $elements = [];
        foreach (['UE1', 'UE2', 'UE3', 'UE4', 'UE5'] as $code) {
            $ecs = array_values(array_filter(self::MARIE, static fn (array $ec): bool => $ec[0] === $code));
            [$response, $json] = $this->api('POST', '/subjects', 'censor', [
                'code' => $code, 'name' => $code, 'kind' => 'teaching_unit', 'credits' => 6,
                'elements' => array_map(static fn (array $ec): array => ['name' => $ec[1], 'credits' => $ec[2]], $ecs),
            ]);
            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
            foreach ($json['data']['elements'] as $element) {
                $elements[$element['name']] = [$json['data']['id'], $element['id']];
            }
        }
        foreach (self::MARIE as [, $name, , $cc, $exam]) {
            [$subjectId, $elementId] = $elements[$name];
            foreach (['cc' => $cc, 'exam' => $exam] as $kind => $score) {
                $id = $this->exam($subjectId, $elementId, $kind, $kind . ' ' . $name);
                $this->normalAssessments[] = $id;
                [$batch] = $this->api('POST', '/grades/batch', 'censor', ['assessment_id' => $id, 'entries' => array_map(
                    static fn (string $p): array => ['person_id' => $p, 'value' => $score],
                    ['p1', 'p2', 'p3', 'p4'],
                )]);
                self::assertSame(200, $batch->getStatusCode(), (string) $batch->getBody());
            }
        }
        [$this->mathsSubject, $this->mathsElement] = $elements['Mathématiques financières'];

        [$rules, $json] = $this->api('POST', '/calculation-rules', 'censor', [
            'academic_year_id' => 'ay1', 'structure_id' => 'c1', 'label' => 'LMD 2026-2027',
            'definition' => [
                'strategy' => 'lmd_test', 'strategy_options' => ['cc_weight' => 0.4, 'exam_weight' => 0.6, 'eliminatory' => 8],
                'decision_labels' => ['admitted_with_debts' => ['label' => 'Admis avec dettes', 'passing' => true], 'repeat' => 'Redouble', 'excluded' => 'Exclu pour insuffisance'],
            ],
        ]);
        self::assertSame(201, $rules->getStatusCode(), (string) $rules->getBody());
        self::assertSame('c1', $json['data']['structure_id']);
        self::assertSame('lmd_test', $json['data']['effective']['strategy']);
    }

    private function exam(string $subjectId, string $elementId, string $kind, string $title, string $session = 'normal'): string
    {
        [$response, $json] = $this->api('POST', '/assessments', 'censor', [
            'subject_id' => $subjectId, 'element_id' => $elementId, 'structure_id' => 'c1', 'period_id' => 'seq1', 'academic_year_id' => 'ay1',
            'kind' => $kind, 'title' => $title, 'max_score' => 20, 'exam_session' => $session,
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $json['data']['id'];
    }

    /** @return array<string, mixed> */
    private function open(string $session = 'normal'): array
    {
        [$response, $json] = $this->api('POST', '/deliberations', 'jury', ['structure_id' => 'c1', 'period_id' => 'seq1', 'period_label' => 'Semestre 1', 'president_id' => 'm1', 'members_expected' => 2, 'exam_session' => $session]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /** @return array<string, mixed> délibération close */
    private function sitAndClose(string $id): array
    {
        $this->api('POST', '/deliberations/' . $id . '/attendance', 'jury', ['members_present' => ['m1', 'm2']]);
        $this->api('POST', '/deliberations/' . $id . '/start', 'jury');
        [$closed, $json] = $this->api('POST', '/deliberations/' . $id . '/close', 'jury');
        self::assertSame(200, $closed->getStatusCode(), (string) $closed->getBody());

        return $json['data'];
    }

    public function testStrategieInconnueRefuseeEtReglesLimiteesALaStructure(): void
    {
        [$unknown] = $this->api('POST', '/calculation-rules', 'censor', ['academic_year_id' => 'ay1', 'label' => 'x', 'definition' => ['strategy' => 'absente']]);
        self::assertSame(422, $unknown->getStatusCode());
        [$badOptions] = $this->api('POST', '/calculation-rules', 'censor', ['academic_year_id' => 'ay1', 'label' => 'x', 'definition' => ['strategy' => 'lmd_test', 'strategy_options' => ['eliminatory' => 'huit']]]);
        self::assertSame(422, $badOptions->getStatusCode());

        $calculator = $this->container()->get(RuleCalculator::class);
        self::assertSame('lmd_test', $calculator->rulesFor('t1', 'e1', 'ay1', 'c1')->strategy);
        self::assertSame('standard', $calculator->rulesFor('t1', 'e1', 'ay1', 'c2')->strategy, 'Une autre structure garde les règles de l\'année.');
        self::assertSame(8.0, $calculator->rulesFor('t1', 'e1', 'ay1', 'c1')->number('eliminatory', 7.0));
    }

    public function testSessionNormaleRattrapageGardesEtReouverture(): void
    {
        // RG-DEL-1 : notes encore en saisie.
        [$unlocked, $refusal] = $this->api('POST', '/deliberations', 'jury', ['structure_id' => 'c1', 'period_id' => 'seq1', 'president_id' => 'm1', 'members_expected' => 2]);
        self::assertSame(409, $unlocked->getStatusCode());
        self::assertStringContainsString('verrouillées', $refusal['detail']);
        foreach ($this->normalAssessments as $id) {
            $this->lock($id);
        }

        // Session normale, scénario B : 11,55, 24 crédits, UE2 non acquise, proposition « Rattrapage ».
        $normal = $this->open();
        $marie = array_column($normal['lines'], null, 'person_id')['p1'];
        self::assertEquals(11.55, $marie['average']);
        self::assertSame([24, 30], [$marie['credits_earned'], $marie['credits_total']]);
        self::assertSame('Rattrapage', $marie['proposal']);
        self::assertSame(['acquired', 'not_acquired', 'acquired', 'acquired', 'acquired'], array_column($marie['unit_averages'], 'status'));
        self::assertEquals(['gpa' => 2.4], $marie['line_extras']);
        self::assertContains('Admis avec dettes', $normal['decisions']);
        self::assertSame('normal', $normal['exam_session']);

        // Clôture : 2FA, empreinte, PV numéro 1.
        $this->api('POST', '/deliberations/' . $normal['id'] . '/attendance', 'jury', ['members_present' => ['m1', 'm2']]);
        $this->api('POST', '/deliberations/' . $normal['id'] . '/start', 'jury');
        [$no2fa] = $this->api('POST', '/deliberations/' . $normal['id'] . '/close', 'jury');
        self::assertSame(403, $no2fa->getStatusCode());
        $this->secondFactor->recent = true;
        [, $closed] = $this->api('POST', '/deliberations/' . $normal['id'] . '/close', 'jury');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $closed['data']['minutes_fingerprint']);
        self::assertSame(1, $closed['data']['minutes_version']);

        // Publication refusée avant la signature du président ; signature refusée si les décisions ont été altérées.
        [$unsigned] = $this->api('POST', '/results/publish', 'censor', ['structure_id' => 'c1', 'period_id' => 'seq1']);
        self::assertSame(409, $unsigned->getStatusCode());
        $this->connections()->pdo()->exec("UPDATE deliberation_lines SET decision = 'Admis' WHERE person_id = 'p2'");
        [$tampered] = $this->api('POST', '/deliberations/' . $normal['id'] . '/sign', 'jury');
        self::assertSame(409, $tampered->getStatusCode());
        $this->connections()->pdo()->exec("UPDATE deliberation_lines SET decision = 'Rattrapage' WHERE person_id = 'p2'");
        [$signed] = $this->api('POST', '/deliberations/' . $normal['id'] . '/sign', 'jury');
        self::assertSame(200, $signed->getStatusCode(), (string) $signed->getBody());
        [$published, $results] = $this->api('POST', '/results/publish', 'censor', ['structure_id' => 'c1', 'period_id' => 'seq1']);
        self::assertSame(200, $published->getStatusCode(), (string) $published->getBody());
        self::assertSame(['normal'], array_values(array_unique(array_column($results['data'], 'exam_session'))));

        // Rattrapage de Mathématiques financières : 11 pour Marie seulement (les autres n'ont pas de note de rattrapage).
        $resit = $this->exam($this->mathsSubject, $this->mathsElement, 'resit', 'Rattrapage maths fi', 'resit');
        $this->grade($resit, 'p1', ['value' => 11]);
        $this->lock($resit);

        $second = $this->open('resit');
        $lines = array_column($second['lines'], null, 'person_id');
        self::assertEquals(12.09, $lines['p1']['average']);
        self::assertSame(30, $lines['p1']['credits_earned']);
        self::assertSame('Admis par compensation', $lines['p1']['proposal']);
        self::assertSame('compensated', $lines['p1']['unit_averages'][1]['status']);
        self::assertEquals(9.8, $lines['p1']['unit_averages'][1]['average']);
        self::assertSame('Ajourné', $lines['p2']['proposal']);
        $resultRows = array_column($this->rows("SELECT person_id, mention, exam_session FROM results WHERE exam_session = 'resit'"), null, 'person_id');
        self::assertSame('Assez bien', $resultRows['p1']['mention']);
        self::assertCount(8, $this->rows('SELECT id FROM results'), 'Une ligne de résultat par personne et par session.');

        $closedResit = $this->sitAndClose((string) $second['id']);
        self::assertSame('closed', $closedResit['status']);
        self::assertContains('p1', $this->container()->get(AdmissionDirectory::class)->admittedPersonIds('t1', 'e1', 'ay1'));
        self::assertNotContains('p2', $this->container()->get(AdmissionDirectory::class)->admittedPersonIds('t1', 'e1', 'ay1'));

        // Réouverture : droit configuré, PV précédent archivé, alerte au DG, nouveau PV à la clôture suivante.
        [$forbidden] = $this->api('POST', '/deliberations/' . $second['id'] . '/reopen', 'director', ['reason' => 'Erreur de saisie']);
        self::assertSame(403, $forbidden->getStatusCode());
        [$reopened, $json] = $this->api('POST', '/deliberations/' . $second['id'] . '/reopen', 'academic', ['reason' => 'Erreur de saisie']);
        self::assertSame(200, $reopened->getStatusCode(), (string) $reopened->getBody());
        self::assertSame('open', $json['data']['status']);
        self::assertNull($json['data']['minutes_fingerprint']);
        self::assertSame(1, $json['data']['minutes_history'][0]['version']);
        self::assertSame([['person_id' => 'dg', 'template' => 'higher.deliberation_reopened']], array_map(static fn (array $m): array => ['person_id' => $m['person_id'], 'template' => $m['template']], $this->messenger->sent));

        [, $again] = $this->api('POST', '/deliberations/' . $second['id'] . '/close', 'jury');
        self::assertSame(2, $again['data']['minutes_version']);
        self::assertCount(3, $this->issuer->issued, 'PV de la session normale, du rattrapage, puis nouveau PV après réouverture.');
    }
}
