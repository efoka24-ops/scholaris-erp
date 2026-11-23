<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Imports\Application\LineChecker;
use Scholaris\Modules\Socle\Imports\Domain\PeopleColumns;
use Scholaris\Modules\Socle\Imports\Infrastructure\TableReader;

final class LineCheckerTest extends TestCase
{
    public function testTelephoneEtEmailNormalises(): void
    {
        self::assertSame('+237691000001', LineChecker::phone('6 91 00 00 01'));
        self::assertSame('+237691000001', LineChecker::phone('00237691000001'));
        self::assertNull(LineChecker::phone('12'));
        self::assertSame('a@b.cm', LineChecker::email(' A@B.cm '));
        self::assertNull(LineChecker::email('pas-un-mail'));
    }

    public function testDates(): void
    {
        self::assertSame('2026-11-02', LineChecker::date('02/11/2026'));
        self::assertSame('2026-11-02', LineChecker::date('2026-11-02'));
        self::assertSame('2026-11-02', LineChecker::date('46328'));
        self::assertNull(LineChecker::date('2026-02-31'));
    }

    public function testEntetesEtSynonymes(): void
    {
        self::assertSame('first_name', PeopleColumns::keyOf('Prénoms'));
        self::assertSame('email', PeopleColumns::keyOf('E-mail'));
        self::assertSame('scope_code', PeopleColumns::keyOf('Code du périmètre'));
        self::assertNull(PeopleColumns::keyOf('inconnu'));
    }

    public function testCsvVirguleEtWindows1252(): void
    {
        $csv = mb_convert_encoding("nom,prénoms,rôle\nÉbogo,Zoé,enseignant\n", 'Windows-1252', 'UTF-8');
        $table = (new TableReader())->read($csv);
        self::assertSame('Ébogo', $table['rows'][2]['last_name']);
    }
}
