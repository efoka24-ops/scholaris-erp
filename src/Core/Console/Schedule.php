<?php

declare(strict_types=1);

namespace Scholaris\Core\Console;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Tâches planifiées de la plateforme. L'hébergement (Camoo) n'accepte qu'une commande `php -f <script>` par tâche cron :
 * une seule tâche, chaque minute, lance `cron.php` (= `artisan schedule:run`), qui exécute les commandes dues ci-dessous.
 * Expressions au format cron (minute heure jour mois jour-de-semaine), à l'heure de l'application (Africa/Douala).
 */
final class Schedule
{
    /** @var list<array{0: string, 1: list<string>}> */
    public const TASKS = [
        ['* * * * *', ['queue:send']],
        ['* * * * *', ['documents:process-jobs']],
        ['0 * * * *', ['attendance:retry-absences']],
        ['0 2 * * *', ['backup:run']],
        ['0 3 * * *', ['reporting:aggregate-territories']],
        ['0 5 * * *', ['reports:run']],
        ['0 6 * * *', ['platform:billing-run']],
        ['30 6 * * *', ['higher:expiry-alerts']],
        ['45 6 * * *', ['higher:reserve-expiry']],
        ['0 7 * * *', ['reminders:send']],
        ['15 7 * * *', ['higher:grading-reminders']],
        ['30 7 * * *', ['higher:reminders']],
        ['45 7 * * *', ['primary:alerts']],
        ['0 8 * * *', ['public:absence-alerts']],
        ['5 8 * * *', ['public:dropout-alerts']],
        ['*/15 12-18 * * *', ['nursery:pickup-watch']],
        ['*/15 12-18 * * *', ['public-nursery:pickup-watch']],
        ['0 19 * * *', ['public-nursery:pickup-audit']],
        ['10 19 * * *', ['nursery:consistency-check']],
        ['0 20 * * *', ['nursery:observation-reminders']],
        ['5 20 * * *', ['public-nursery:observation-reminders']],
        ['30 3 * * 0', ['nursery:purge-media']],
        ['40 3 * * 0', ['public-nursery:purge-media']],
        // La purge effective (--apply) reste une décision manuelle : le cron ne fait que le rapport.
        ['0 4 1 * *', ['retention:run']],
    ];

    /** @return list<list<string>> commandes (avec arguments) dues à cette minute */
    public static function due(DateTimeImmutable $at): array
    {
        $due = [];
        foreach (self::TASKS as [$expression, $command]) {
            if (self::matches($expression, $at)) {
                $due[] = $command;
            }
        }

        return $due;
    }

    public static function matches(string $expression, DateTimeImmutable $at): bool
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($fields) !== 5) {
            throw new InvalidArgumentException('Expression cron invalide : ' . $expression);
        }
        $values = [(int) $at->format('i'), (int) $at->format('G'), (int) $at->format('j'), (int) $at->format('n'), (int) $at->format('w')];
        foreach ($fields as $i => $field) {
            if (!self::fieldMatches($field, $values[$i])) {
                return false;
            }
        }

        return true;
    }

    /** Un champ : `*`, `*\/n`, `a`, `a-b`, `a-b/n` ou une liste de ces formes séparées par des virgules. */
    private static function fieldMatches(string $field, int $value): bool
    {
        foreach (explode(',', $field) as $part) {
            if (preg_match('/^(\*|(\d+)(?:-(\d+))?)(?:\/(\d+))?$/', $part, $m) !== 1) {
                throw new InvalidArgumentException('Champ cron invalide : ' . $field);
            }
            $m += [2 => '', 3 => '', 4 => ''];
            $from = $m[1] === '*' ? 0 : (int) $m[2];
            $to = $m[1] === '*' ? PHP_INT_MAX : ($m[3] !== '' ? (int) $m[3] : $from);
            $step = $m[4] !== '' ? max(1, (int) $m[4]) : 1;
            if ($value >= $from && $value <= $to && ($value - $from) % $step === 0) {
                return true;
            }
        }

        return false;
    }
}
