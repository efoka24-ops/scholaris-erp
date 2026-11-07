<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Console;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;

/**
 * `platform:admin-create <e-mail|téléphone> <prénom> <nom...>` : crée un administrateur de plateforme.
 * Ordre fixe, sans guillemets (le terminal de l'hébergement coupe aux espaces) : 1er argument l'identifiant de connexion,
 * 2e le prénom, TOUS les suivants forment le nom (« platform:admin-create emm.foka@gmail.com Emmanuel Foka Ndjock »).
 * Mot de passe généré, affiché une seule fois ; double authentification exigée dès la première connexion.
 */
final class AdminCreateCommand
{
    private const TRIM = " \t\"'";

    public function __construct(private readonly PlatformAdministrators $administrators)
    {
    }

    /**
     * @param list<string> $args
     * @return array{identifier: string, first_name: string, last_name: string}|null
     */
    public static function parse(array $args): ?array
    {
        $args = array_values(array_filter(array_map(static fn (string $a): string => trim($a, self::TRIM), $args), static fn (string $a): bool => $a !== ''));
        if (count($args) < 3) {
            return null;
        }

        return ['identifier' => $args[0], 'first_name' => $args[1], 'last_name' => implode(' ', array_slice($args, 2))];
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $parsed = self::parse($args);
        if ($parsed === null) {
            fwrite(STDERR, "Usage : artisan platform:admin-create <e-mail|téléphone> <prénom> <nom (un ou plusieurs mots)>\n");

            return 2;
        }

        try {
            $result = $this->administrators->create($parsed['identifier'], $parsed['first_name'], $parsed['last_name']);
        } catch (HttpException $e) {
            $details = [];
            foreach ($e->errors as $messages) {
                $details[] = implode(' ', $messages);
            }
            fwrite(STDERR, 'Erreur : ' . $e->getMessage() . ($details === [] ? '' : ' ' . implode(' ', $details)) . "\n");

            return 1;
        }

        echo "Administrateur de plateforme créé : {$parsed['first_name']} {$parsed['last_name']}.\n";
        echo "  Identifiant  : {$result['login']}\n";
        echo "  Mot de passe : {$result['password']}   (affiché une seule fois ; à changer dès la première connexion)\n";
        echo "La double authentification est OBLIGATOIRE pour ce compte, même si AUTH_2FA_REQUIRED=false.\n";

        return 0;
    }
}
