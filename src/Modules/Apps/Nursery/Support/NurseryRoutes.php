<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Support;

use Scholaris\Core\Http\RouteRegistry;

/**
 * Seul moyen d'enregistrer une route de la Maternelle privée. Le préfixe `/nursery` est ajouté ici ; chaque route passe par
 * GatedAction, lectures comprises. Aucune route sans authentification dans ce module.
 */
final class NurseryRoutes
{
    public const PREFIX = '/nursery';

    public function __construct(private readonly RouteRegistry $registry)
    {
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $path, array $handler): void
    {
        $this->add('GET', 'read', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $path, array $handler): void
    {
        $this->add('POST', 'write', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function put(string $path, array $handler): void
    {
        $this->add('PUT', 'write', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function patch(string $path, array $handler): void
    {
        $this->add('PATCH', 'write', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function delete(string $path, array $handler): void
    {
        $this->add('DELETE', 'write', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    private function add(string $method, string $mode, string $path, array $handler): void
    {
        if (!str_starts_with($path, '/') || !str_starts_with($handler[0], 'Scholaris\\Modules\\Apps\\Nursery\\')) {
            throw new \LogicException('Route de la Maternelle privée invalide : ' . $path);
        }
        $this->registry->add($method, self::PREFIX . $path, [GatedAction::class, GatedAction::encode($mode, $handler)]);
    }
}
