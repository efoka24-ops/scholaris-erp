<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Console;

use Scholaris\Modules\Apps\Nursery\Development\Application\ObservationService;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;

/**
 * `nursery:observation-reminders [AAAA-MM]` : rappel hebdomadaire de la fréquence d'observation. Pour chaque école, nombre d'écarts
 * (enfant x domaine sous le minimum mensuel de /settings) par section. N'écrit rien et n'envoie rien : le résultat alimente le tableau
 * de la direction (comparaison de sections, jamais d'enfants entre eux).
 */
final class ObservationRemindersCommand
{
    public const NAME = 'nursery:observation-reminders';

    public function __construct(
        private readonly SectionService $sections,
        private readonly ObservationService $observations,
        private readonly NurseryClock $clock,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $month = $args[0] ?? substr($this->clock->today(), 0, 7);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            fwrite(STDERR, "Usage : nursery:observation-reminders [AAAA-MM]\n");

            return 2;
        }
        $total = 0;
        foreach ($this->sections->establishments() as [$tenantId, $establishmentId]) {
            foreach ($this->sections->inPerimeter(new Perimeter($tenantId, $establishmentId, true)) as $section) {
                $gaps = $this->observations->gapCount($tenantId, $establishmentId, (string) $section['id'], $month);
                $total += $gaps;
                echo sprintf("- %s / %s / section %s : %d écart(s) de fréquence en %s\n", $tenantId, $establishmentId, $section['label'], $gaps, $month);
            }
        }
        echo sprintf("Rappels d'observation (%s) : %d écart(s) au total.\n", $month, $total);

        return 0;
    }
}
