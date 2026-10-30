<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Console;

use Scholaris\Modules\Apps\PublicSchools\Attendance\Application\AbsenceAlertService;

/**
 * `public:absence-alerts [AAAA-MM-JJ]` : alertes d'assiduité des écoles publiques (NOT-03 absences répétées, NOT-12 classe sans appel).
 * À planifier une fois par jour, en fin de journée. Clé de conteneur `console.command.public:absence-alerts`.
 */
final class AbsenceAlertsCommand
{
    public const NAME = 'public:absence-alerts';

    public function __construct(private readonly AbsenceAlertService $alerts)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $day = $args[0] ?? null;
        if ($day !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4)))) {
            fwrite(STDERR, "Usage : public:absence-alerts [AAAA-MM-JJ]\n");

            return 2;
        }
        $result = $this->alerts->run($day);
        echo sprintf(
            "Alertes d'assiduité : %d alerte(s), %d message(s) ; %d client(s) sauté(s) (application suspendue ou fermée).\n",
            $result['alerts'],
            $result['messages'],
            $result['skipped_tenants'],
        );

        return 0;
    }
}
