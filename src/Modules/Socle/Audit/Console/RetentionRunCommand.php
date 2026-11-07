<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Console;

use Scholaris\Modules\Socle\Audit\Application\RetentionService;

/** `retention:run [--apply] [client]` : simulation par défaut ; --apply anonymise réellement les données d'Audit échues. */
final class RetentionRunCommand
{
    public function __construct(private readonly RetentionService $retention)
    {
    }

    /** @param list<string> $args arguments après le nom de la commande */
    public function run(array $args): int
    {
        $apply = in_array('--apply', $args, true);
        $rest = array_values(array_filter($args, static fn (string $a): bool => $a !== '--apply'));
        foreach ($rest as $arg) {
            if (str_starts_with($arg, '--')) {
                fwrite(STDERR, "Option inconnue : {$arg}\nUsage : artisan retention:run [--apply] [identifiant du client]\n");

                return 2;
            }
        }

        echo $apply ? "Rétention : application réelle.\n" : "Rétention : SIMULATION (aucune donnée modifiée ; ajouter --apply pour appliquer).\n";
        foreach ($this->retention->run($rest[0] ?? null, $apply) as $line) {
            echo "  client {$line['tenant_id']} : {$line['requests']} demande(s), {$line['approvals']} approbation(s) à anonymiser\n";
        }

        return 0;
    }
}
