<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Core;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Tests\Support\Nursery\NurseryTestCase;

/** EX-MAT-23 : la première section pose `attendance.mode = day_part` (SettingsWriter), sans jamais écraser le choix de l'école. */
final class DayPartPresetTest extends NurseryTestCase
{
    public function testPreReglageALaPremiereSection(): void
    {
        $this->bootNursery();
        $this->ok('POST', '/nursery/sections', ['label' => 'Petite section (exemple)']);
        self::assertSame('day_part', $this->service(SettingsReader::class)->value(self::TA, self::EA, 'attendance.mode'));
        self::assertNull($this->service(SettingsReader::class)->value(self::TB, self::EB, 'attendance.mode'), 'Autre client non touché.');
    }

    public function testUnChoixDeLEcoleNEstJamaisEcrase(): void
    {
        $this->bootNursery();
        $this->setSetting(self::TA, self::EA, 'attendance.mode', 'slot');
        $this->ok('POST', '/nursery/sections', ['label' => 'Petite section (exemple)']);
        self::assertSame('slot', $this->service(SettingsReader::class)->value(self::TA, self::EA, 'attendance.mode'));
    }
}
