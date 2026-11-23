<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use RuntimeException;
use Scholaris\Core\Support\Ulid;

/**
 * Stockage privé des logos, hors racine web : <racine>/tenants/<client>/establishments/<établissement>/logo/<ulid>.<ext>.
 * Le nom du fichier est toujours généré ici (jamais pris du client) ; toute lecture reste sous la racine.
 */
final class LogoStorage
{
    public function __construct(private readonly string $root)
    {
    }

    /** Préfixe logique d'un établissement (celui qu'impose aussi UpdateBrandingRequest). */
    public static function prefix(string $tenantId, string $establishmentId): string
    {
        foreach ([$tenantId, $establishmentId] as $segment) {
            if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $segment) !== 1) {
                throw new RuntimeException('Segment de chemin invalide.');
            }
        }

        return sprintf('tenants/%s/establishments/%s/logo/', $tenantId, $establishmentId);
    }

    /** @return string chemin logique relatif à la racine */
    public function put(string $tenantId, string $establishmentId, string $extension, string $content): string
    {
        if (preg_match('/^(png|jpg)$/', $extension) !== 1) {
            throw new RuntimeException('Extension invalide.');
        }
        $relative = self::prefix($tenantId, $establishmentId) . new Ulid() . '.' . $extension;
        $absolute = $this->root . '/' . $relative;
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0750, true) && !is_dir(dirname($absolute))) {
            throw new RuntimeException('Impossible de créer le dossier de stockage.');
        }
        if (file_put_contents($absolute, $content, LOCK_EX) === false) {
            throw new RuntimeException('Impossible d\'écrire le logo.');
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
        if (!str_starts_with($relative, 'tenants/') || str_contains($relative, '..')) {
            return null;
        }
        $root = realpath($this->root);
        $path = realpath($this->root . '/' . $relative);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        return $path;
    }
}
