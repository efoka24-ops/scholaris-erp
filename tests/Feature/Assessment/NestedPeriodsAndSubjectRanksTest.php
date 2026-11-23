<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Scholaris\Modules\Socle\Assessment\Application\ReportCardService;
use Scholaris\Modules\Socle\Assessment\Application\ResultService;
use Tests\Support\Assessment\AggregatingTestStrategy;
use Tests\Support\Assessment\ApplicationReportCardVariables;
use Tests\Support\Assessment\AssessmentTestCase;
use Tests\Support\Assessment\ConfigurableCouncilLines;

/**
 * Socle Assessment pour le secondaire (vague 0, T805 à T807) : agrégat récursif année → trimestres → séquences (SS3),
 * rang et statistiques par matière (SS4), préfixe des variables de bulletin et type par nature de période (SS5),
 * valeurs propres des lignes de conseil (SS6). Sans paramètre ni extension : comportement historique.
 *
 * Données (coefficients 1) : séquence S1 maths/français p1 16/12, p2 12/12, p3 8/8, p4 10/14 ; S2 tout à 10 ;
 * S3 p1 20/20, p2 12/12, p3 8/8, p4 12/12. T1 = S1 + S2, T2 = S3, Y = T1 + T2.
 */
final class NestedPeriodsAndSubjectRanksTest extends AssessmentTestCase
{
    private const GRADES = [
        'S1' => ['p1' => [16, 12], 'p2' => [12, 12], 'p3' => [8, 8], 'p4' => [10, 14]],
        'S2' => ['p1' => [10, 10], 'p2' => [10, 10], 'p3' => [10, 10], 'p4' => [10, 10]],
        'S3' => ['p1' => [20, 20], 'p2' => [12, 12], 'p3' => [8, 8], 'p4' => [12, 12]],
    ];

    /** @var array<string, list<string>> épreuves par séquence */
    private array $exams = [];

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $overrides
     */
    private function boot(array $settings = [], array $overrides = []): void
    {
        AggregatingTestStrategy::$received = [];
        ConfigurableCouncilLines::$lines = [];
        $this->bootAssessment($settings, $overrides + ['assessment.rule_strategies.test' => [AggregatingTestStrategy::class]]);
        $this->seedClass();
        $this->periods();
    }

    /** Année Y, trimestres T1 et T2, séquences S1 et S2 (dans T1, insérées dans le désordre) et S3 (dans T2). */
    private function periods(): void
    {
        $insert = $this->connections()->pdo()->prepare('INSERT INTO periods (id, tenant_id, establishment_id, academic_year_id, kind, ordinal, starts_on, ends_on, parent_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Y', 'year', 1, '2026-09-01', '2027-06-30', null],
            ['T1', 'trimester', 1, '2026-09-01', '2026-12-20', 'Y'],
            ['T2', 'trimester', 2, '2027-01-04', '2027-03-31', 'Y'],
            ['S2', 'sequence', 2, '2026-10-20', '2026-12-20', 'T1'],
            ['S1', 'sequence', 1, '2026-09-01', '2026-10-19', 'T1'],
            ['S3', 'sequence', 3, '2027-01-04', '2027-03-31', 'T2'],
        ] as [$id, $kind, $ordinal, $from, $to, $parent]) {
            $insert->execute([$id, 't1', 'e1', 'ay1', $kind, $ordinal, $from, $to, $parent, '2026-08-01T08:00:00+01:00', '2026-08-01T08:00:00+01:00']);
        }
    }

    private function gradeAll(bool $lock = false): void
    {
        $math = $this->subject('MATH', 'subject', 1.0);
        $french = $this->subject('FRAN', 'subject', 1.0);
        foreach (self::GRADES as $period => $grades) {
            foreach ([$math, $french] as $index => $subject) {
                [$response, $json] = $this->api('POST', '/assessments', 'censor', [
                    'subject_id' => $subject, 'structure_id' => 'c1', 'period_id' => $period, 'academic_year_id' => 'ay1', 'kind' => 'devoir',
                    'title' => 'Devoir ' . $period . ' ' . $index, 'weight' => 1.0, 'max_score' => 20.0,
                ]);
                self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
                $exam = (string) $json['data']['id'];
                $this->exams[$period][] = $exam;
                foreach ($grades as $person => $values) {
                    $this->grade($exam, $person, ['value' => $values[$index]]);
                }
                if ($lock) {
                    $this->lock($exam);
                }
            }
        }
    }

    /** @return array<string, array<string, mixed>> résultats calculés d'une période, par élève */
    private function compute(string $period): array
    {
        $rows = $this->container()->get(ResultService::class)->compute('t1', 'e1', 'c1', $period, 'Période ' . $period)['rows'];

        return array_column($rows, null, 'person_id');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, array<string, mixed>> détail par code de matière
     */
    private static function subjects(array $row): array
    {
        $out = [];
        foreach ((array) json_decode((string) $row['details'], true) as $detail) {
            $out[(string) $detail['code']] = $detail;
        }

        return $out;
    }

    public function testLAnneeAgregeLesTrimestresQuiAgregentLesSequencesDansLOrdre(): void
    {
        $this->boot(['assessment.strategy' => AggregatingTestStrategy::KEY]);
        $this->gradeAll();

        $year = $this->compute('Y');

        self::assertEquals([16.0, 11.5, 8.5, 11.5], [$year['p1']['average'], $year['p2']['average'], $year['p3']['average'], $year['p4']['average']]);
        self::assertSame([1, 2, 4, 2], [(int) $year['p1']['rank_in_class'], (int) $year['p2']['rank_in_class'], (int) $year['p3']['rank_in_class'], (int) $year['p4']['rank_in_class']], 'Ex æquo au rang 2.');
        self::assertSame('Période Y', $year['p1']['period_label']);
        self::assertSame(['children' => 2], json_decode((string) $year['p1']['computed_extras'], true), 'Valeurs propres de la stratégie conservées.');

        $subjects = self::subjects($year['p1']);
        self::assertSame(['MATH', 'FRAN'], array_keys($subjects));
        self::assertEquals(16.5, $subjects['MATH']['average'], 'Maths : T1 (16 + 10) / 2 = 13, T2 = 20.');
        self::assertSame('Matière MATH', $subjects['MATH']['name']);
        self::assertSame(2, $subjects['MATH']['periods']);
        self::assertIsString($subjects['MATH']['subject_id']);

        $received = array_values(array_unique(array_map(static fn (array $ids): string => implode(',', $ids), AggregatingTestStrategy::$received)));
        self::assertSame(['S1,S2', 'S3', 'T1,T2'], $received, 'Enfants calculés d\'abord, reçus dans l\'ordre des rangs.');

        $stored = array_column($this->rows("SELECT period_id, person_id, average FROM results WHERE person_id = 'p1' ORDER BY period_id"), 'average', 'period_id');
        self::assertEquals(['S1' => 14.0, 'S2' => 10.0, 'S3' => 20.0, 'T1' => 12.0, 'T2' => 20.0, 'Y' => 16.0], array_map('floatval', $stored));
    }

    public function testSansStrategieAgregeanteLaPeriodeParenteGardeLeCalculHistorique(): void
    {
        $this->boot();
        $this->gradeAll();

        $year = $this->compute('Y');

        self::assertNull($year['p1']['average'], 'Moteur standard : la période n\'a pas d\'épreuve propre.');
        self::assertSame([], json_decode((string) $year['p1']['details'], true));
        self::assertSame([], $this->rows("SELECT id FROM results WHERE period_id <> 'Y'"), 'Aucun enfant calculé.');
        self::assertSame([], AggregatingTestStrategy::$received);
    }

    public function testRangEtStatistiquesParMatiereAvecExAequo(): void
    {
        $this->boot(['assessment.subject_ranks' => true]);
        $this->gradeAll();

        $sequence = $this->compute('S1');

        $p1 = self::subjects($sequence['p1']);
        self::assertSame(1, $p1['MATH']['rank_in_subject']);
        self::assertSame(2, $p1['FRAN']['rank_in_subject'], 'Français : p1 et p2 ex æquo derrière p4.');
        self::assertSame(2, self::subjects($sequence['p2'])['FRAN']['rank_in_subject']);
        self::assertSame(1, self::subjects($sequence['p4'])['FRAN']['rank_in_subject']);
        self::assertSame(4, self::subjects($sequence['p3'])['FRAN']['rank_in_subject']);
        self::assertSame(3, self::subjects($sequence['p4'])['MATH']['rank_in_subject']);
        self::assertEquals([11.5, 8.0, 16.0], [$p1['MATH']['class_subject_avg'], $p1['MATH']['class_subject_min'], $p1['MATH']['class_subject_max']]);
        self::assertEquals([11.5, 8.0, 14.0], [$p1['FRAN']['class_subject_avg'], $p1['FRAN']['class_subject_min'], $p1['FRAN']['class_subject_max']]);
        self::assertEquals(['class_avg' => 11.5, 'class_min' => 8.0, 'class_max' => 14.0], json_decode((string) $sequence['p3']['computed_extras'], true));

        [$response, $json] = $this->api('GET', '/results?filter[structure_id]=c1&filter[period_id]=S1', 'censor');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $first = $json['data'][0];
        self::assertArrayHasKey('rank_in_subject', $first['details'][0]);
        self::assertArrayHasKey('class_avg', $first['computed_extras']);
    }

    public function testRangParMatiereSurUnePeriodeAgregee(): void
    {
        $this->boot(['assessment.strategy' => AggregatingTestStrategy::KEY, 'assessment.subject_ranks' => true]);
        $this->gradeAll();

        $year = $this->compute('Y');

        $p1 = self::subjects($year['p1']);
        self::assertSame(1, $p1['MATH']['rank_in_subject']);
        self::assertEquals(16.5, $p1['MATH']['class_subject_max']);
        self::assertEquals(['children' => 2, 'class_avg' => 11.88, 'class_min' => 8.5, 'class_max' => 16.0], json_decode((string) $year['p1']['computed_extras'], true));
    }

    public function testParDefautLeDetailEstInchange(): void
    {
        $this->boot();
        $this->gradeAll();

        $sequence = $this->compute('S1');

        foreach (self::subjects($sequence['p1']) as $detail) {
            self::assertSame(['subject_id', 'code', 'name', 'average'], array_slice(array_keys($detail), 0, 4));
            foreach (['rank_in_subject', 'class_subject_avg', 'class_subject_min', 'class_subject_max'] as $key) {
                self::assertArrayNotHasKey($key, $detail);
            }
        }
        self::assertNull($sequence['p1']['computed_extras']);
    }

    public function testConseilAnnuelResumeParMatiereValidationEtBulletinParNatureDePeriode(): void
    {
        $this->boot([
            'assessment.strategy' => AggregatingTestStrategy::KEY,
            'assessment.report_card_types' => ['sequence' => 'secondary_report_sequence', 'year' => 'secondary_report_annual'],
        ], ['assessment.report_card_variables.secondary' => [ApplicationReportCardVariables::class]]);
        $this->issuer->knownTypes = ['class_council_minutes', 'secondary_report_sequence', 'secondary_report_annual'];
        $this->gradeAll(true);

        [$response, $json] = $this->api('POST', '/class-councils', 'council', ['structure_id' => 'c1', 'period_id' => 'Y', 'period_label' => 'Année 2026-2027']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $council = $json['data'];
        self::assertSame(['Matière MATH', 'Matière FRAN'], array_column($council['summary']['subject_averages'], 'name'), 'Moyennes par matière dans le résumé du conseil annuel.');
        self::assertEquals(11.88, $council['summary']['subject_averages'][0]['average']);
        self::assertEquals(16.0, array_column($council['lines'], null, 'person_id')['p1']['average']);

        [$validated] = $this->api('POST', '/class-councils/' . $council['id'] . '/validate', 'council');
        self::assertSame(200, $validated->getStatusCode(), (string) $validated->getBody());

        [$cards, $cardsJson] = $this->api('POST', '/class-councils/' . $council['id'] . '/report-cards', 'council');
        self::assertSame(200, $cards->getStatusCode(), (string) $cards->getBody());
        self::assertSame(['issued', 'issued', 'issued', 'issued'], array_column($cardsJson['data'], 'status'));
        $card = array_column(array_values(array_filter($this->issuer->issued, static fn (array $d): bool => $d['type'] !== 'class_council_minutes')), null, 'person')['p1'];
        self::assertSame('secondary_report_annual', $card['type']);
        self::assertSame('Bonne', $card['data']['secondary_conduct'], 'Préfixe de l\'application.');
        self::assertSame('ancien', $card['data']['primary_legacy'], 'Préfixe d\'origine toujours accepté.');
        self::assertArrayNotHasKey('higher_gpa', $card['data']);
        self::assertArrayNotHasKey('secondaryx', $card['data']);
        self::assertEquals(16.0, $card['data']['average'], 'Une variable du socle n\'est jamais remplacée.');
    }

    public function testTypeDeBulletinParDefautEtRepliSurLeTypeUnique(): void
    {
        $this->boot(['assessment.report_card_types' => ['year' => 'secondary_report_annual'], 'assessment.report_card_type' => 'school_report']);
        $settings = $this->container()->get(\Scholaris\Modules\Socle\Assessment\Application\AssessmentSettings::class);

        self::assertSame('secondary_report_annual', $settings->reportCardType('t1', 'e1', 'year', ReportCardService::DOCUMENT_TYPE));
        self::assertSame('school_report', $settings->reportCardType('t1', 'e1', 'sequence', ReportCardService::DOCUMENT_TYPE));
        $this->settings->values = ['assessment.report_card_types' => ['year' => 'Mauvais type!']];
        self::assertSame('report_card', $settings->reportCardType('t1', 'e1', 'year', ReportCardService::DOCUMENT_TYPE));
        self::assertTrue(ReportCardService::accepts('public_primary_x', 'public_primary'));
        self::assertFalse(ReportCardService::accepts('secondary_', 'secondary'));
    }

    public function testValeursPropresDesLignesDeConseilJamaisEcraseesParLeRecalcul(): void
    {
        $this->boot([], ['assessment.council_line_providers.test' => [ConfigurableCouncilLines::class]]);
        ConfigurableCouncilLines::$lines = ['p1' => ['conduct' => 'Bonne', 'extras' => ['distinction_refused' => true, 'absence_hours' => 4]]];
        $this->gradeAll();

        [$response, $json] = $this->api('POST', '/class-councils', 'council', ['structure_id' => 'c1', 'period_id' => 'S1', 'period_label' => 'Séquence 1']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $lines = array_column($json['data']['lines'], null, 'person_id');
        self::assertSame(['distinction_refused' => true, 'absence_hours' => 4], $lines['p1']['line_extras']);
        self::assertNull($lines['p2']['line_extras']);

        [$patched, $patchedJson] = $this->api('PATCH', '/class-councils/' . $json['data']['id'] . '/lines/' . $lines['p1']['id'], 'council', ['line_extras' => ['distinction_refused' => false]]);
        self::assertSame(200, $patched->getStatusCode(), (string) $patched->getBody());
        self::assertSame(['distinction_refused' => false, 'absence_hours' => 4], array_column($patchedJson['data']['lines'], null, 'person_id')['p1']['line_extras']);

        [$invalid] = $this->api('PATCH', '/class-councils/' . $json['data']['id'] . '/lines/' . $lines['p1']['id'], 'council', ['line_extras' => ['Bad Key' => 1]]);
        self::assertSame(422, $invalid->getStatusCode());

        ConfigurableCouncilLines::$lines = [
            'p1' => ['extras' => ['distinction_refused' => true, 'practice_alert' => 'atelier']],
            'p2' => ['extras' => ['absence_hours' => 2]],
        ];
        [$refreshed, $refreshedJson] = $this->api('POST', '/class-councils/' . $json['data']['id'] . '/refresh', 'council');
        self::assertSame(200, $refreshed->getStatusCode(), (string) $refreshed->getBody());
        $after = array_column($refreshedJson['data']['lines'], null, 'person_id');
        self::assertSame(['distinction_refused' => false, 'absence_hours' => 4, 'practice_alert' => 'atelier'], $after['p1']['line_extras'], 'Saisie en séance conservée, clé nouvelle ajoutée.');
        self::assertSame(['absence_hours' => 2], $after['p2']['line_extras']);
        self::assertSame('Bonne', $after['p1']['conduct']);
    }
}
