<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Console;

use Scholaris\Modules\Socle\Integrations\Contracts\CartescolaireExporter;
use Scholaris\Modules\Socle\Integrations\Contracts\TutelleExporter;

/** `external:export <cartescolaire|tutelle> <client> <établissement> <fichier>` : export fichier générique vers un organisme. */
final class ExternalExportCommand
{
    public function __construct(
        private readonly CartescolaireExporter $cartescolaire,
        private readonly TutelleExporter $tutelle,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        if (count($args) !== 4 || !in_array($args[0], ['cartescolaire', 'tutelle'], true) || in_array('', $args, true)) {
            fwrite(STDERR, "Usage : artisan external:export <cartescolaire|tutelle> <client> <établissement> <fichier>\n");

            return 2;
        }
        $exporter = $args[0] === 'cartescolaire' ? $this->cartescolaire : $this->tutelle;
        $report = $exporter->export($args[1], $args[2], $args[3]);
        echo "Export {$args[0]} ({$report->format}) écrit dans {$report->path} : {$report->rows} ligne(s), colonnes : " . implode(', ', $report->columns) . "\n";

        return 0;
    }
}
