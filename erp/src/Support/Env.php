<?php

declare(strict_types=1);

namespace Scholaris\Support;

use RuntimeException;

/**
 * Lecture du fichier .env.
 *
 * Les valeurs ne sont volontairement pas poussees dans $_ENV ni dans
 * getenv() : elles restent dans ce conteneur, pour qu'un var_dump() de
 * l'environnement ou un phpinfo() n'expose pas les identifiants de base.
 */
final class Env
{
    /** @var array<string, string> */
    private array $values = [];

    private function __construct()
    {
    }

    public static function load(string $path): self
    {
        $env = new self();

        if (! is_file($path)) {
            return $env;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $position = strpos($line, '=');

            if ($position === false) {
                continue;
            }

            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            // Retire les guillemets encadrants, sans toucher a ceux du contenu.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $env->values[$key] = $value;
        }

        return $env;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Valeur obligatoire : l'absence est une erreur de configuration, pas un
     * cas a gerer silencieusement au milieu d'une requete.
     */
    public function require(string $key): string
    {
        $value = $this->values[$key] ?? '';

        if ($value === '') {
            throw new RuntimeException("Configuration manquante dans .env : {$key}");
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
