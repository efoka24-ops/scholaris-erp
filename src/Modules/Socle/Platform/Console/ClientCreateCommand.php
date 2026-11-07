<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Console;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Platform\Application\AdministratorAccounts;
use Scholaris\Modules\Socle\Platform\Application\ClientLifecycle;
use Scholaris\Modules\Socle\Platform\Application\EntitlementService;

/**
 * `platform:client-create <application> <type> <e-mail|téléphone> <prénom> <nom> <nom de l'établissement...> [--ville=…] [--region=…] [--dry-run]`
 *
 * Mise en service d'un client en une commande (lot I4, pilote) : client et premier établissement, application ouverte en essai,
 * premier administrateur invité (invitation par e-mail ou SMS selon l'identifiant). Ordre fixe et sans guillemets : le terminal de
 * l'hébergement coupe aux espaces ; tous les mots après le nom de l'administrateur forment le nom de l'établissement.
 * Exemple : `platform:client-create higher higher direction@isseg.cm Jean Directeur Institut Supérieur du Septentrion de Garoua --ville=Garoua --region=Nord`
 */
final class ClientCreateCommand
{
    public const NAME = 'platform:client-create';
    private const ACTOR = 'console';
    private const TYPES = ['nursery', 'primary', 'secondary', 'higher'];
    private const TRIM = " \t\"'";

    public function __construct(
        private readonly ClientLifecycle $clients,
        private readonly EntitlementService $entitlements,
        private readonly AdministratorAccounts $administrators,
    ) {
    }

    /**
     * @param list<string> $args
     * @return array{app: string, type: string, login: string, first_name: string, last_name: string, name: string, city: ?string, region: ?string, dry_run: bool}|null
     */
    public static function parse(array $args): ?array
    {
        $words = [];
        $options = ['ville' => null, 'region' => null];
        $dryRun = false;
        foreach ($args as $raw) {
            $arg = trim($raw, self::TRIM);
            if ($arg === '') {
                continue;
            }
            if ($arg === '--dry-run') {
                $dryRun = true;
            } elseif (preg_match('/^--(ville|region)=(.+)$/u', $arg, $m) === 1) {
                $options[$m[1]] = str_replace('_', ' ', trim($m[2], self::TRIM));
            } else {
                $words[] = $arg;
            }
        }
        if (count($words) < 6 || !in_array($words[1], self::TYPES, true) || preg_match('/^[a-z][a-z0-9_]+$/', $words[0]) !== 1) {
            return null;
        }

        return [
            'app' => $words[0], 'type' => $words[1], 'login' => $words[2], 'first_name' => $words[3], 'last_name' => $words[4],
            'name' => implode(' ', array_slice($words, 5)), 'city' => $options['ville'], 'region' => $options['region'], 'dry_run' => $dryRun,
        ];
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $p = self::parse($args);
        if ($p === null) {
            fwrite(STDERR, "Usage : artisan platform:client-create <application> <nursery|primary|secondary|higher> <e-mail|téléphone de l'administrateur> <prénom> <nom> <nom de l'établissement...> [--ville=Garoua] [--region=Nord] [--dry-run]\n");

            return 2;
        }
        $isEmail = str_contains($p['login'], '@');
        echo ($p['dry_run'] ? '[SIMULATION] ' : '') . "Client « {$p['name']} » ({$p['type']}), application {$p['app']} en essai, administrateur {$p['first_name']} {$p['last_name']} <{$p['login']}>.\n";
        if ($p['dry_run']) {
            echo "Rien n'a été écrit. Relancez sans --dry-run pour créer.\n";

            return 0;
        }

        try {
            $client = $this->clients->create([
                'name' => $p['name'], 'contact_name' => $p['first_name'] . ' ' . $p['last_name'],
                'contact_email' => $isEmail ? $p['login'] : null, 'contact_phone' => $isEmail ? null : $p['login'],
                'city' => $p['city'], 'region' => $p['region'], 'locale' => 'fr', 'establishment_type' => $p['type'],
            ], self::ACTOR, null);
            $tenantId = (string) $client['id'];
            $entitlement = $this->entitlements->open($tenantId, $p['app'], EntitlementService::SOURCE_PLATFORM, self::ACTOR);
            $admin = $this->administrators->invite($tenantId, $p['name'], [
                'first_name' => $p['first_name'], 'last_name' => $p['last_name'],
                'phone' => $isEmail ? null : $p['login'], 'email' => $isEmail ? $p['login'] : null, 'locale' => 'fr',
            ], self::ACTOR, null);
        } catch (HttpException $e) {
            $details = [];
            foreach ($e->errors as $messages) {
                $details[] = implode(' ', array_map('strval', (array) $messages));
            }
            fwrite(STDERR, 'Erreur : ' . $e->getMessage() . ($details === [] ? '' : ' ' . implode(' ', $details)) . "\n");

            return 1;
        }

        $establishments = (array) ($client['establishments'] ?? []);
        $establishment = (array) ($establishments[0] ?? []);
        echo "Client créé.\n";
        echo "  Client         : {$tenantId}\n";
        echo '  Établissement  : ' . ($establishment['id'] ?? '?') . "\n";
        echo '  Application    : ' . $p['app'] . ' (' . ($entitlement['state'] ?? '?') . ")\n";
        echo "  Administrateur : {$p['login']} (en attente d'activation)\n";
        echo '  Invitation     : ' . ($admin['invitation'] ?? 'non envoyée') . "\n";
        echo "L'administrateur active son compte sur /activation avec son identifiant (code reçu par " . ($isEmail ? 'e-mail' : 'SMS') . ").\n";

        return 0;
    }
}
