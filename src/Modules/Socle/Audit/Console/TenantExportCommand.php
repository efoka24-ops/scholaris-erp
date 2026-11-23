<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Console;

use Scholaris\Modules\Socle\Audit\Application\TenantExporter;

/** `tenant:export <tenant_id> <fichier>` : archive ZIP de toutes les données d'un client. */
final class TenantExportCommand
{
    public const ACTOR = 'console';

    public function __construct(private readonly TenantExporter $exporter)
    {
    }

    /** @param list<string> $args arguments après le nom de la commande */
    public function run(array $args): int
    {
        if (count($args) !== 2 || $args[0] === '' || $args[1] === '') {
            fwrite(STDERR, "Usage : artisan tenant:export <identifiant du client> <fichier.zip>\n");

            return 2;
        }
        $manifest = $this->exporter->exportToFile($args[0], self::ACTOR, $args[1]);
        $total = 0;
        foreach ((array) $manifest['sources'] as $tables) {
            $total += array_sum((array) $tables);
        }
        echo "Export du client {$args[0]} écrit dans {$args[1]} : " . count((array) $manifest['sources']) . " source(s), {$total} ligne(s).\n";

        return 0;
    }
}
