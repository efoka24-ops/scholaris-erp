<?php

declare(strict_types=1);

namespace Tests\Architecture;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Console\Schedule;
use Scholaris\Core\Kernel;

/** Tâches planifiées lancées par cron.php : chaque commande existe et se construit, les horaires tombent juste. */
final class ScheduleTest extends TestCase
{
    /** Commandes traitées directement par Console\Application (sans clé de conteneur). */
    private const BUILT_IN = ['reminders:send', 'retention:run', 'attendance:retry-absences', 'queue:send', 'reports:run', 'documents:process-jobs'];

    public function testChaqueTachePlanifieeDesigneUneCommandeConstruisible(): void
    {
        $container = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]))->container();
        $failures = [];
        foreach (Schedule::TASKS as [, $command]) {
            $name = $command[0];
            if (in_array($name, self::BUILT_IN, true)) {
                continue;
            }
            try {
                $object = $container->get('console.command.' . $name);
                if (!method_exists($object, 'run')) {
                    $failures[] = $name . ' : pas de méthode run';
                }
            } catch (\Throwable $e) {
                $failures[] = $name . ' : ' . $e->getMessage();
            }
        }

        self::assertSame([], $failures);
    }

    public function testHorairesDesExpressions(): void
    {
        $at = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s);
        self::assertTrue(Schedule::matches('* * * * *', $at('2026-10-28 13:47')));
        self::assertTrue(Schedule::matches('30 6 * * *', $at('2026-10-28 06:30')));
        self::assertFalse(Schedule::matches('30 6 * * *', $at('2026-10-28 06:31')));
        self::assertTrue(Schedule::matches('*/15 12-18 * * *', $at('2026-10-28 12:45')));
        self::assertFalse(Schedule::matches('*/15 12-18 * * *', $at('2026-10-28 19:00')));
        self::assertFalse(Schedule::matches('*/15 12-18 * * *', $at('2026-10-28 13:10')));
        self::assertTrue(Schedule::matches('30 3 * * 0', $at('2026-11-01 03:30')), 'Le 1er novembre 2026 est un dimanche.');
        self::assertTrue(Schedule::matches('0 4 1 * *', $at('2026-11-01 04:00')));
        self::assertTrue(Schedule::matches('0,30 8 * * 1-5', $at('2026-10-28 08:30')));

        $due = Schedule::due($at('2026-10-28 06:00'));
        self::assertContains(['platform:billing-run'], $due);
        self::assertContains(['queue:send'], $due);
        self::assertContains(['attendance:retry-absences'], $due);
    }

    public function testCronToutesLes15MinutesRattrapeChaqueTacheUneSeuleFois(): void
    {
        $due = Schedule::dueBetween(new DateTimeImmutable('2026-10-28 06:15'), new DateTimeImmutable('2026-10-28 06:30'));
        self::assertContains(['higher:expiry-alerts'], $due, '6 h 30 tombe dans la fenêtre.');
        self::assertSame(1, count(array_keys($due, ['queue:send'], true)), 'Une tâche de chaque minute ne tourne qu\'une fois.');
        self::assertNotContains(['platform:billing-run'], $due, '6 h 00 est avant la fenêtre.');

        $due = Schedule::dueBetween(new DateTimeImmutable('2026-10-28 05:55'), new DateTimeImmutable('2026-10-28 06:10'));
        self::assertContains(['platform:billing-run'], $due);
        self::assertContains(['attendance:retry-absences'], $due);

        // Cron interrompu une journée : seules les 120 dernières minutes sont rattrapées.
        $due = Schedule::dueBetween(new DateTimeImmutable('2026-10-27 00:00'), new DateTimeImmutable('2026-10-28 08:10'));
        self::assertContains(['public:absence-alerts'], $due);
        self::assertNotContains(['backup:run'], $due);
    }

    public function testUneExpressionMalFormeeEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Schedule::matches('*/x * * *', new DateTimeImmutable());
    }
}
