<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Console;

use Scholaris\Core\Database\ConnectionFactory;

/** Rappels de fréquence des observations : liste les élèves dont la dernière observation remonte au-delà du seuil. */
final class ObservationRemindersCommand
{
    public const NAME = 'public-nursery:observation-reminders';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $days = 7;
        $today = new \DateTimeImmutable('today');
        foreach ($args as $arg) {
            if (preg_match('/^--days=(\d{1,3})$/', $arg, $match) === 1) {
                $days = max(1, (int) $match[1]);
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg) === 1) {
                $today = new \DateTimeImmutable($arg);
            } else {
                fwrite(STDERR, "Usage : public-nursery:observation-reminders [AAAA-MM-JJ] [--days=N]\n");

                return 2;
            }
        }

        $threshold = $today->modify('-' . $days . ' days')->format('Y-m-d');
        $statement = $this->connections->pdo()->prepare(
            'SELECT tenant_id, establishment_id, learner_id, MAX(observed_on) AS last_seen FROM pn_observations GROUP BY tenant_id, establishment_id, learner_id HAVING last_seen < ? ORDER BY tenant_id, establishment_id, learner_id',
        );
        $statement->execute([$threshold]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        echo sprintf("Rappels d'observation : %d élève(s) sans observation depuis %s (seuil %d jour(s)).\n", count($rows), $threshold, $days);
        foreach ($rows as $row) {
            echo sprintf("- %s / %s / %s : dernière observation %s\n", $row['tenant_id'], $row['establishment_id'], $row['learner_id'], $row['last_seen']);
        }

        return 0;
    }
}
