<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

use Scholaris\Core\Http\RouteRegistry;

final class PrimaryRoutes
{
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
        if (!str_starts_with($path, '/') || !str_starts_with($handler[0], 'Scholaris\\Modules\\Apps\\Primary\\')) {
            throw new \LogicException('Route Primary invalide.');
        }
        $this->registry->add($method, '/primary' . $path, [GatedAction::class, GatedAction::encode($mode, $handler)]);
    }
}
