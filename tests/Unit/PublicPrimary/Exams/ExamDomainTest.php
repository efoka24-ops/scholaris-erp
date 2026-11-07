<?php

declare(strict_types=1);

namespace Tests\Unit\PublicPrimary\Exams;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Domain\ExamResults;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Domain\VacationStatement;

/** Taux de réussite = admis ÷ présents (CDC) ; état des vacations aux taux fournis (décret 2010/1738 : taux en paramètres). */
final class ExamDomainTest extends TestCase
{
    public function testLeTauxDeReussiteRapporteLesAdmisAuxSeulsPresents(): void
    {
        $summary = ExamResults::summary(['passed' => 30, 'failed' => 10, 'absent' => 5]);

        self::assertSame(45, $summary['candidates']);
        self::assertSame(40, $summary['present']);
        self::assertSame(75.0, $summary['success_rate']);
        self::assertNull(ExamResults::summary(['absent' => 3])['success_rate'], 'Aucun présent : pas de taux.');
    }

    public function testLImportReconnaitLesResultatsEnFrancaisEtEnAnglais(): void
    {
        $rows = ExamResults::fromCsv("\xEF\xBB\xBFcandidate_number,result\nA-1,Admis\nA-2,FAILED\nA-3,ajournée\nA-4,peut-être\n");

        self::assertSame(['passed', 'failed', 'failed', null], array_column($rows, 'result'));
        self::assertSame(5, $rows[3]['line']);
        self::assertSame('passed', ExamResults::fromList([['learner_number' => 'SYN-1', 'result' => 'pass']])[0]['result']);
    }

    public function testLesVacationsAppliquentLesTauxFournisEtLesSurchargesParFonction(): void
    {
        $statement = VacationStatement::compute(
            [
                ['id' => 'o1', 'centre_id' => 'c', 'full_name' => 'Fictif A', 'function_code' => 'marker', 'half_days' => 2, 'copies_marked' => 10],
                ['id' => 'o2', 'centre_id' => 'c', 'full_name' => 'Fictif B', 'function_code' => 'jury_president', 'half_days' => 2, 'copies_marked' => 0],
            ],
            ['half_day' => 100, 'copy' => 7, 'director_per_candidate' => 3, 'per_function' => ['jury_president' => ['half_day' => 400]]],
            ['e2' => 4, 'e1' => 10],
        );

        self::assertSame(2 * 100 + 10 * 7, $statement['lines'][0]['amount']);
        self::assertSame(2 * 400, $statement['lines'][1]['amount']);
        self::assertSame(['e1', 'e2'], array_column($statement['directors'], 'establishment_id'));
        self::assertSame(14 * 3, $statement['total_directors']);
        self::assertSame(270 + 800 + 42, $statement['total']);
    }
}
