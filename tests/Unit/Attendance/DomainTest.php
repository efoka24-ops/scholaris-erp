<?php

declare(strict_types=1);

namespace Tests\Unit\Attendance;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Attendance\Application\AttendanceSettings;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceCodes;
use Scholaris\Modules\Socle\Attendance\Domain\Adulthood;
use Scholaris\Modules\Socle\Attendance\Domain\AssiduityLevel;
use Scholaris\Modules\Socle\Attendance\Domain\AttendanceStatus;
use Tests\Support\Attendance\ArraySettings;

final class DomainTest extends TestCase
{
    public function testLeNiveauDAssiduiteSuitLesSeuils(): void
    {
        self::assertSame(AssiduityLevel::Ok, AssiduityLevel::of(24.9, 30, 25));
        self::assertSame(AssiduityLevel::Alert, AssiduityLevel::of(25.0, 30, 25));
        self::assertSame(AssiduityLevel::Alert, AssiduityLevel::of(30.0, 30, 25), 'Le seuil lui-même n\'interdit pas de composer : c\'est « au-dessus ».');
        self::assertSame(AssiduityLevel::NotAllowed, AssiduityLevel::of(30.1, 30, 25));
        self::assertSame(AssiduityLevel::Ok, AssiduityLevel::of(0, 30, 25));
    }

    public function testLesStatutsSontPAR(): void
    {
        self::assertSame(['P', 'A', 'R'], AttendanceStatus::values());
    }

    public function testLesConstantesDeContratSontCellesDuDomaine(): void
    {
        self::assertSame(AttendanceStatus::values(), AttendanceCodes::STATUSES);
        self::assertSame(
            [AttendanceStatus::Present->value, AttendanceStatus::Absent->value, AttendanceStatus::Late->value],
            [AttendanceCodes::PRESENT, AttendanceCodes::ABSENT, AttendanceCodes::LATE],
        );
    }

    public function testLaBaseDesTauxEstLesSeancesTenuesParDefaut(): void
    {
        self::assertSame(AttendanceCodes::BASE_HELD, (new AttendanceSettings(new ArraySettings()))->base('t1', 'e1'));
        self::assertSame(AttendanceCodes::BASE_PLANNED, (new AttendanceSettings(new ArraySettings(['attendance.base' => 'planned'])))->base('t1', 'e1'));
        self::assertSame(AttendanceCodes::BASE_HELD, (new AttendanceSettings(new ArraySettings(['attendance.base' => 'PLANNED'])))->base('t1', 'e1'));
    }

    public function testLaMajoriteEstA18Ans(): void
    {
        self::assertTrue(Adulthood::isAdult('2008-10-08', '2026-10-08'));
        self::assertFalse(Adulthood::isAdult('2008-10-09', '2026-10-08'));
        self::assertFalse(Adulthood::isAdult(null, '2026-10-08'), 'Sans date connue : mineur.');
        self::assertFalse(Adulthood::isAdult('pas une date', '2026-10-08'));
    }

    public function testLesParametresOntDesDefautsEtDesBornes(): void
    {
        $defaults = new AttendanceSettings(new ArraySettings());
        self::assertSame(30.0, $defaults->maxUnjustifiedPercent('t', 'e'));
        self::assertSame(25.0, $defaults->alertPercent('t', 'e'));
        self::assertTrue($defaults->notifyParents('t', 'e'));
        self::assertSame(10, $defaults->qrWindowMinutes('t', 'e'));
        self::assertSame(AttendanceSettings::DEFAULT_SCALE, $defaults->sanctionScale('t', 'e'));

        $odd = new AttendanceSettings(new ArraySettings([
            'attendance.max_unjustified_percent' => '20', 'attendance.alert_unjustified_percent' => 80, 'attendance.notify_parents' => '0',
            'attendance.qr_window_minutes' => 9999, 'attendance.sanction_scale' => ['Retenue', 'retenue', 'retenue', 12],
        ]));
        self::assertSame(20.0, $odd->maxUnjustifiedPercent('t', 'e'));
        self::assertSame(20.0, $odd->alertPercent('t', 'e'), 'L\'alerte ne dépasse jamais le seuil maximal.');
        self::assertFalse($odd->notifyParents('t', 'e'));
        self::assertSame(120, $odd->qrWindowMinutes('t', 'e'));
        self::assertSame(['retenue'], $odd->sanctionScale('t', 'e'));
    }
}
