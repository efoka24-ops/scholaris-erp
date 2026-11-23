<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Support;

use Scholaris\Core\Http\RouteRegistry;

/**
 * Seul moyen d'enregistrer une route Higher. Chaque chemin est préfixé par `/higher` ; chaque route passe par GatedAction :
 * GET contrôle la lecture (application fermée = 404), POST/PUT/PATCH/DELETE contrôlent l'écriture par WriteGate (suspendue = 423).
 *
 * Un sous-module déclare ses routes dans `Higher/<Module>/routes.php` :
 *     return static function (HigherRoutes $routes): void {
 *         $routes->post('/academic/schools', [SchoolController::class, 'create']);
 *     };
 * Le fichier racine `Higher/routes.php` les inclut tous (glob) ; il n'est jamais modifié par les sous-modules.
 */
final class HigherRoutes
{
    public const PREFIX = '/higher';

    public function __construct(private readonly RouteRegistry $registry)
    {
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, bool> $options
     */
    public function get(string $path, array $handler, array $options = []): void
    {
        $this->add('GET', 'read', $path, $handler, $options);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, bool> $options
     */
    public function post(string $path, array $handler, array $options = []): void
    {
        $this->add('POST', 'write', $path, $handler, $options);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, bool> $options
     */
    public function put(string $path, array $handler, array $options = []): void
    {
        $this->add('PUT', 'write', $path, $handler, $options);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, bool> $options
     */
    public function patch(string $path, array $handler, array $options = []): void
    {
        $this->add('PATCH', 'write', $path, $handler, $options);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, bool> $options
     */
    public function delete(string $path, array $handler, array $options = []): void
    {
        $this->add('DELETE', 'write', $path, $handler, $options);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, bool> $options
     */
    private function add(string $method, string $mode, string $path, array $handler, array $options): void
    {
        if (($options['public'] ?? false) === true || ($options['auth'] ?? true) === false) {
            throw new \LogicException('Une route Higher exige une authentification : ' . $method . ' ' . $path);
        }
        if (!str_starts_with($path, '/')) {
            throw new \LogicException('Chemin Higher invalide : ' . $path);
        }
        $this->registry->add($method, self::PREFIX . $path, [GatedAction::class, GatedAction::encode($mode, $handler)], $options);
    }
}
