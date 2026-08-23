<?php

declare(strict_types=1);

namespace Scholaris\Http;

use Scholaris\Http\Exception\HttpException;

/**
 * Routeur.
 *
 * Chaque route porte la permission RBAC exigee (ressource:action). Elle est
 * declaree avec la route, pas verifiee dans le controleur : une route sans
 * permission explicite est un choix visible dans le fichier de routes, jamais
 * un oubli enfoui au milieu d'une methode.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: callable|array, permission: ?string, guest: bool}> */
    private array $routes = [];

    /**
     * @param  callable|array{0: class-string, 1: string}  $handler
     */
    public function get(string $path, $handler, ?string $permission = null): void
    {
        $this->add('GET', $path, $handler, $permission, false);
    }

    /**
     * @param  callable|array{0: class-string, 1: string}  $handler
     */
    public function post(string $path, $handler, ?string $permission = null): void
    {
        $this->add('POST', $path, $handler, $permission, false);
    }

    /**
     * Route accessible sans authentification (connexion, pages publiques).
     *
     * @param  callable|array{0: class-string, 1: string}  $handler
     */
    public function guest(string $method, string $path, $handler): void
    {
        $this->add(strtoupper($method), $path, $handler, null, true);
    }

    /**
     * @param  callable|array{0: class-string, 1: string}  $handler
     */
    private function add(string $method, string $path, $handler, ?string $permission, bool $guest): void
    {
        $params = [];

        // Transforme /students/{id} en expression reguliere, en capturant les
        // segments nommes. Les valeurs capturees excluent la barre oblique,
        // donc un identifiant ne peut pas traverser plusieurs segments.
        $regex = preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)\}/',
            function (array $matches) use (&$params): string {
                $params[] = $matches[1];

                return '([^/]+)';
            },
            $path
        );

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^'.$regex.'$#',
            'params' => $params,
            'handler' => $handler,
            'permission' => $permission,
            'guest' => $guest,
        ];
    }

    /**
     * Retrouve la route correspondant a la requete.
     *
     * @return array{handler: callable|array, permission: ?string, guest: bool}
     *
     * @throws HttpException 404 si aucun chemin ne correspond, 405 si le chemin
     *                       existe pour une autre methode.
     */
    public function match(Request $request): array
    {
        $path = $request->path();
        $method = $request->method();
        $pathExists = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $pathExists = true;

            if ($route['method'] !== $method) {
                continue;
            }

            foreach ($route['params'] as $index => $name) {
                $request->setAttribute($name, $matches[$index + 1]);
            }

            return [
                'handler' => $route['handler'],
                'permission' => $route['permission'],
                'guest' => $route['guest'],
            ];
        }

        throw new HttpException($pathExists ? 405 : 404);
    }
}
