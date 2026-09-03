<?php

declare(strict_types=1);

namespace Scholaris;

/**
 * Chargement automatique des classes, conforme PSR-4.
 *
 * Ecrit a la main plutot que fourni par Composer : l'application ne depend
 * d'aucun paquet externe, ce qui permet de la deployer par simple copie de
 * fichiers, sans etape d'installation sur le serveur.
 */
final class Autoloader
{
    private string $baseDir;

    private string $prefix;

    public function __construct(string $baseDir, string $prefix = 'Scholaris\\')
    {
        $this->baseDir = rtrim($baseDir, '/\\').DIRECTORY_SEPARATOR;
        $this->prefix = $prefix;
    }

    public static function register(string $baseDir, string $prefix = 'Scholaris\\'): void
    {
        $loader = new self($baseDir, $prefix);
        spl_autoload_register([$loader, 'load']);
    }

    public function load(string $class): void
    {
        if (strncmp($class, $this->prefix, strlen($this->prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($this->prefix));
        $path = $this->baseDir.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';

        if (is_file($path)) {
            require $path;
        }
    }
}
