<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Infrastructure;

use Psr\Container\ContainerInterface;
use Scholaris\Modules\Socle\Sync\Application\HandlerRegistry;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;

/**
 * Cherche le gestionnaire sous la clé de conteneur `sync.handlers.<kind>`. Le type vient du client : il n'est jamais utilisé
 * comme nom de classe, seulement comme suffixe d'une clé au préfixe fixe, après contrôle strict de son format ; la valeur
 * trouvée doit être un BatchItemHandler.
 */
final class ContainerHandlerRegistry implements HandlerRegistry
{
    public const PREFIX = 'sync.handlers.';
    public const KIND_FORMAT = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/D';
    public const KIND_MAX_LENGTH = 60;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function for(string $kind): ?BatchItemHandler
    {
        if (strlen($kind) > self::KIND_MAX_LENGTH || preg_match(self::KIND_FORMAT, $kind) !== 1) {
            return null;
        }
        $key = self::PREFIX . $kind;
        if (!$this->container->has($key)) {
            return null;
        }
        $handler = $this->container->get($key);

        return $handler instanceof BatchItemHandler ? $handler : null;
    }
}
