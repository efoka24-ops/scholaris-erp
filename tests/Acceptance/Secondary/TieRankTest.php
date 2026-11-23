<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\SecondaryStrategy;
use Scholaris\Modules\Socle\Assessment\Application\ResultService;
use Scholaris\Modules\Socle\Assessment\Application\RuleStrategies;
use Tests\Support\Assessment\AssessmentTestCase;

/**
 * TST-04 (T943) de bout en bout sur le socle Assessment réel (SQLite migrée) avec la stratégie `secondary` enregistrée par
 * `Secondary/Councils/definitions.php` (aucune liaison de test) : trimestre T1 = séquences S1 et S2 ; deux élèves ex æquo au
 * 3e rang, le suivant 5e. Le même parcours recalcule l'exemple TST-01 (Aïcha M., 11 matières) : moyenne 11,30 enregistrée,
 * valeurs calculées dans `computed_extras`.
 */
final class TieRankTest extends AssessmentTestCase
{
    private function boot(): void
    {
        $this->bootAssessment(['assessment.strategy' => SecondaryStrategy::KEY, 'assessment.strategy_options' => Defaults::STRATEGY_OPTIONS]);
        $this->seedClass();
        $this->people->add('t1', 'p5', 'Eyenga', 'Essomba');
        $this->enrollments->add('t1', 'p5', 'e1', 'c1', 'MAT-P5');
        $this->teaching->classes['t1'][] = 'p5';
        $insert = $this->connections()->pdo()->prepare('INSERT INTO periods (id, tenant_id, establishment_id, academic_year_id, kind, ordinal, starts_on, ends_on, parent_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['T1', 'trimester', 1, '2026-09-01', '2026-12-20', null],
            ['S1', 'sequence', 1, '2026-09-01', '2026-10-19', 'T1'],
            ['S2', 'sequence', 2, '2026-10-20', '2026-12-20', 'T1'],
        ] as [$id, $kind, $ordinal, $from, $to, $parent]) {
            $insert->execute([$id, 't1', 'e1', 'ay1', $kind, $ordinal, $from, $to, $parent, '2026-08-01T08:00:00+01:00', '2026-08-01T08:00:00+01:00']);
        }
    }

    /** @param array<string, array{0: float|int, 1: float|int}> $grades élève => [S1, S2] */
    private function graded(string $code, float $coefficient, array $grades): void
    {
        $subject = $this->subject($code, 'subject', $coefficient);
        foreach (['S1' => 0, 'S2' => 1] as $period => $index) {
            [$response, $json] = $this->api('POST', '/assessments', 'censor', [
                'subject_id' => $subject, 'structure_id' => 'c1', 'period_id' => $period, 'academic_year_id' => 'ay1', 'kind' => 'devoir',
                'title' => 'Devoir ' . $code . ' ' . $period, 'weight' => 1.0, 'max_score' => 20.0,
            ]);
            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
            foreach ($grades as $person => $values) {
                $this->grade((string) $json['data']['id'], $person, ['value' => $values[$index]]);
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function trimester(): array
    {
        return array_column($this->container()->get(ResultService::class)->compute('t1', 'e1', 'c1', 'T1', '1er trimestre')['rows'], null, 'person_id');
    }

    public function testDeuxTroisiemesExAequoLeSuivantCinquieme(): void
    {
        $this->boot();
        self::assertTrue($this->container()->get(RuleStrategies::class)->has(SecondaryStrategy::KEY), 'Stratégie enregistrée par Councils/definitions.php.');
        $this->graded('FRAN', 2.0, ['p1' => [16, 14], 'p2' => [14, 12], 'p3' => [12, 12], 'p4' => [13, 11], 'p5' => [10, 10]]);

        $rows = $this->trimester();

        self::assertEquals([15.0, 13.0, 12.0, 12.0, 10.0], [$rows['p1']['average'], $rows['p2']['average'], $rows['p3']['average'], $rows['p4']['average'], $rows['p5']['average']]);
        self::assertSame([1, 2, 3, 3, 5], array_map(static fn (array $row): int => (int) $row['rank_in_class'], [$rows['p1'], $rows['p2'], $rows['p3'], $rows['p4'], $rows['p5']]));
        self::assertSame(5, (int) $rows['p1']['class_size']);
    }

    public function testExempleAichaRecalculeParLeSocle(): void
    {
        $this->boot();
        foreach (AichaTrimesterTest::SUBJECTS as $code => [$coefficient, $s1, $s2]) {
            $others = ['p2' => [10, 10], 'p3' => [12, 12], 'p4' => [8, 9], 'p5' => [14, 15]];
            $this->graded(strtoupper($code), (float) $coefficient, ['p1' => [$s1, $s2]] + $others);
        }

        $rows = $this->trimester();

        self::assertEquals(11.30, $rows['p1']['average']);
        self::assertSame('1er trimestre', $rows['p1']['period_label']);
        $extras = json_decode((string) $rows['p1']['computed_extras'], true);
        self::assertSame(1, $extras['depth']);
        self::assertNull($extras['work_proposal']);
        self::assertCount(1, $extras['subject_alerts'], 'Mathématiques à 7,75.');
        $details = array_column((array) json_decode((string) $rows['p1']['details'], true), null, 'code');
        self::assertEquals(7.75, $details['MATH']['average']);
        self::assertSame($details['MATH']['subject_id'], $extras['subject_alerts'][0]);
        self::assertEquals(4, $details['MATH']['coefficient']);

        $sequence = array_column($this->rows("SELECT person_id, average, proposal FROM results WHERE period_id = 'S1'"), null, 'person_id');
        self::assertEquals(round((12 * 4 + 9 * 3 + 8.5 * 4 + 11 * 2 + 14 * 2 + 10 * 2 + 13 + 15 + 16 + 12 + 14) / 22, 2), $sequence['p1']['average'], 'Séquence 1 calculée et enregistrée.');
        self::assertSame('Encouragements et tableau d\'honneur', $sequence['p5']['proposal'] ?? null, 'p5 : 14 partout en séquence 1, tranche d\'exemple « 14 à moins de 16 ».');
    }
}
