<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Console;

use Scholaris\Modules\Apps\Higher\Grades\Application\GradingReminders;

/**
 * `higher:grading-reminders [AAAA-MM-JJ]` : relances NOT-11 des saisies de notes en retard (EX-NOT-08). À planifier une fois par
 * jour. Clé de conteneur `console.command.higher:grading-reminders`. Un client suspendu ou fermé est sauté.
 */
final class GradingRemindersCommand
{
    public const NAME = 'higher:grading-reminders';

    public function __construct(private readonly GradingReminders $reminders)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $day = $args[0] ?? null;
        if ($day !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4)))) {
            fwrite(STDERR, "Usage : higher:grading-reminders [AAAA-MM-JJ]\n");

            return 2;
        }
        $result = $this->reminders->run($day);
        echo sprintf(
            "Relances de saisie : %d évaluation(s) en retard, %d notification(s) ; %d client(s) sauté(s) (application suspendue ou fermée).\n",
            $result['assessments'],
            $result['notifications'],
            $result['skipped_tenants'],
        );

        return 0;
    }
}
