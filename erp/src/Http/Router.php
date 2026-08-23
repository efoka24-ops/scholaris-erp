<?php

declare(strict_types=1);

namespace Scholaris\Http;

use Scholaris\Http\Exception\HttpException;

/**
 * Routeur.
 *
 * Chaque route porte la permission RBAC exigee (ressource:action) et, le cas
 * echeant, la fonctionnalite dont elle depend. Les deux sont declarees avec la
 * route, pas verifiees dans le controleur : une route sans controle se voit
 * immediatement dans ce fichier, jamais enfouie au milieu d'une methode.
 *
 * La distinction compte : une permission manquante donne 403 (« vous n'y avez
 * pas droit »), une fonctionnalite absente donne 404 (« cela n'existe pas
 * ici »). Un directeur d'ecole primaire ne doit pas apprendre l'existence du
 * baccalaureat par un message de refus.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: callable|array, permission: ?string, feature: ?string, guest: bool}> */
    private array $routes = [];

    /**
     * @param  callable|array{0: class-string, 1: string}  $handler
     */
    public function get(string $path, $handler, ?string $permission = null, ?string $feature = null): void
    {
        $this->add('GET', $path, $handler, $permission, $feature, false);
    }

    /**
     * @param  callable|array{0: class-string, 1: string}  $handler
     */
    public function post(string $path, $handler, ?string $permission = null, ?string $feature = null): void
    {
        $this->add('POST', $path, $handler, $permission, $feature, false);
    }

    /**
     * Route accessible sans authentification (connexion, pages publiques).
     *
     * @param  callable|array{0: class-string, 1: string}  $handler
     */
    public function guest(string $method, string $path, $handler): void
    {
        $this->add(strtoupper($method), $path, $handler, null, null, true);
    }

    /**
     * @param  callable|array{0: class-string, 1: string}  $handler
     */
    private function add(string $method, string $path, $handler, ?string $permission, ?string $feature, bool $guest): void
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
            'feature' => $feature,
            'guest' => $guest,
        ];
    }

    /**
     * Retrouve la route correspondant a la requete.
     *
     * @return array{handler: callable|array, permission: ?string, feature: ?string, guest: bool}
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
                'feature' => $route['feature'],
                'guest' => $route['guest'],
            ];
        }

        throw new HttpException($pathExists ? 405 : 404);
    }
}
