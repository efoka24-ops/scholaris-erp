<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Console;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Migration\Application\PilotSetup;
use Scholaris\Modules\Apps\Higher\Migration\Domain\PilotTemplates;

/**
 * `higher:pilot-setup <id du client> <modèle> [--establishment=<id>] [--year=<id de l'année>] [--dry-run]` : installe le squelette
 * d'un institut pilote déjà créé (écoles, départements, filières-gabarits fermées « à confirmer », niveaux, groupes, profil documentaire,
 * grille de frais vide) sans aucune autorisation MINESUP. Idempotente. Clé de conteneur `console.command.higher:pilot-setup`.
 */
final class PilotSetupCommand
{
    public const NAME = 'higher:pilot-setup';

    public function __construct(private readonly PilotSetup $setup)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $positional = [];
        $options = ['establishment' => null, 'year' => null];
        $dryRun = false;
        foreach ($args as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
            } elseif (preg_match('/^--(establishment|year)=(.+)$/', $arg, $m) === 1) {
                $options[$m[1]] = trim($m[2]);
            } elseif (str_starts_with($arg, '--')) {
                fwrite(STDERR, 'Option inconnue : ' . $arg . "\n");

                return 2;
            } else {
                $positional[] = $arg;
            }
        }
        if (count($positional) !== 2) {
            fwrite(STDERR, "Usage : higher:pilot-setup <id du client> <modèle> [--establishment=<id>] [--year=<id>] [--dry-run]\nModèles : " . implode(', ', PilotTemplates::names()) . "\n");

            return 2;
        }
        try {
            $result = $this->setup->run($positional[0], $positional[1], $options['establishment'], $options['year'], $dryRun);
        } catch (HttpException $e) {
            fwrite(STDERR, 'Erreur : ' . $e->getMessage() . ' ' . implode(' ', array_merge(...array_values($e->errors ?: [[]]))) . "\n");

            return 1;
        }
        echo ($dryRun ? "SIMULATION (rien n'est écrit)\n" : '') . 'Établissement : ' . $result->establishmentId . ' · modèle : ' . $result->template . "\n";
        foreach ($result->lines as $line) {
            echo '  ' . $line . "\n";
        }
        echo sprintf("Créés : %s ; conservés : %s.\n", json_encode($result->created, JSON_UNESCAPED_UNICODE), json_encode($result->kept, JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
