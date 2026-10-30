<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use RuntimeException;
use Scholaris\Core\Support\Ulid;

/**
 * Stockage privé des pièces, hors racine web : <racine>/<client>/people/<personne>/<ulid>.<ext>.
 * Le nom du fichier est toujours généré ici, jamais pris du client.
 */
final class FileStorage
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return string chemin relatif à la racine */
    public function put(string $tenantId, string $personId, string $extension, string $content): string
    {
        return $this->putIn($tenantId, 'people', $personId, $extension, $content);
    }

    /** @return string chemin relatif à la racine : <client>/<domaine>/<propriétaire>/<ulid>.<ext> */
    public function putIn(string $tenantId, string $area, string $ownerId, string $extension, string $content): string
    {
        foreach ([$tenantId, $area, $ownerId] as $segment) {
            if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $segment) !== 1) {
                throw new RuntimeException('Segment de chemin invalide.');
            }
        }
        if (preg_match('/^[a-z]{2,4}$/', $extension) !== 1) {
            throw new RuntimeException('Extension invalide.');
        }
        $relative = $tenantId . '/' . $area . '/' . $ownerId . '/' . new Ulid() . '.' . $extension;
        $absolute = $this->root . '/' . $relative;
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0750, true) && !is_dir(dirname($absolute))) {
            throw new RuntimeException('Impossible de créer le dossier de stockage.');
        }
        if (file_put_contents($absolute, $content, LOCK_EX) === false) {
            throw new RuntimeException('Impossible d\'écrire la pièce.');
        }

        return $relative;
    }

    public function read(string $relative): ?string
    {
        $path = $this->resolve($relative);
        if ($path === null) {
            return null;
        }
        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    public function delete(string $relative): void
    {
        $path = $this->resolve($relative);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function resolve(string $relative): ?string
    {
        $root = realpath($this->root);
        $path = realpath($this->root . '/' . $relative);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        return $path;
    }
}
