<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Support;

use Scholaris\Core\Http\RouteRegistry;

/**
 * Seul moyen d'enregistrer une route de la Maternelle publique. Le préfixe `/public-nursery` est ajouté ici ; chaque route passe
 * par GatedAction : GET contrôle la lecture (application fermée = 404), POST/PUT/PATCH/DELETE contrôlent l'écriture par
 * NurseryWriteGate (suspendue = 423, fermée = 403). Aucune route sans authentification dans ce module.
 *
 * Un sous-module déclare ses routes dans `<Module>/routes.php` :
 *     return static function (NurseryRoutes $routes): void {
 *         $routes->post('/pickup/handovers', [HandoverController::class, 'store']);
 *     };
 */
final class NurseryRoutes
{
    public const PREFIX = '/public-nursery';

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
        if (!str_starts_with($path, '/') || !str_starts_with($handler[0], 'Scholaris\\Modules\\Apps\\PublicNursery\\')) {
            throw new \LogicException('Route de la Maternelle publique invalide : ' . $path);
        }
        $this->registry->add($method, self::PREFIX . $path, [GatedAction::class, GatedAction::encode($mode, $handler)]);
    }
}
