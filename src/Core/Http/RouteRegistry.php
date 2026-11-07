<?php

declare(strict_types=1);

namespace Scholaris\Core\Http;

use FastRoute\RouteCollector;

/**
 * Enregistre les routes déclarées par le cœur et par les modules, avec leurs options :
 *  - auth    (défaut true)  : authentification requise ;
 *  - tenant  (défaut true)  : en-tête X-Establishment-Id obligatoire et contrôlé ;
 *  - public  : synonyme de auth=false et tenant=false.
 * La liste sert aussi aux tests d'isolation et de contrat.
 */
final class RouteRegistry
{
    /** @var list<array{method: string, path: string, handler: array{0: class-string, 1: string}, options: array<string, bool>}> */
    private array $routes = [];

    public function __construct(private readonly string $prefix)
    {
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, bool> $options
     */
    public function add(string $method, string $path, array $handler, array $options = []): void
    {
        if (($options['public'] ?? false) === true) {
            $options = ['auth' => false, 'tenant' => false] + $options;
        }
        $options += ['auth' => true, 'tenant' => true];
        $this->routes[] = ['method' => $method, 'path' => $this->prefix . $path, 'handler' => $handler, 'options' => $options];
    }

    /** @return list<array{method: string, path: string, handler: array{0: class-string, 1: string}, options: array<string, bool>}> */
    public function all(): array
    {
        return $this->routes;
    }

    public function register(RouteCollector $collector): void
    {
        foreach ($this->routes as $route) {
            $collector->addRoute($route['method'], $route['path'], [$route['handler'], $route['options']]);
        }
    }
}
