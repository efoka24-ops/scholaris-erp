<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Support;

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;

/**
 * Seul point d'enregistrement des routes de l'application (chaque `<Module>/routes.php` reçoit cet objet) :
 *  - `get/post/put/patch/delete` : route /secondary/... enveloppée par GatedAction (droit d'usage) ;
 *  - `module('workshops')->get(...)` : même chose, plus la garde de module (409 `module_not_enabled` si le module est inactif ;
 *    lecture seule s'il a été coupé après usage) ;
 *  - `platformGet/platformPost/platformPatch` : route /platform/secondary/... réservée à l'administrateur de plateforme
 *    (PlatformAction), sans en-tête d'établissement.
 */
final class SecondaryRoutes
{
    private const NAMESPACE = 'Scholaris\\Modules\\Apps\\Secondary\\';

    public function __construct(private readonly RouteRegistry $registry, private readonly ?string $module = null)
    {
        if ($module !== null && !in_array($module, Modules::ALL, true)) {
            throw new \LogicException('Module inconnu : ' . $module);
        }
    }

    /** Routes d'un module activable (liste fermée Modules::ALL). */
    public function module(string $module): self
    {
        return new self($this->registry, $module);
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
    public function platformGet(string $path, array $handler): void
    {
        $this->addPlatform('GET', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function platformPost(string $path, array $handler): void
    {
        $this->addPlatform('POST', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function platformPatch(string $path, array $handler): void
    {
        $this->addPlatform('PATCH', $path, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    private function add(string $method, string $mode, string $path, array $handler): void
    {
        self::check($path, $handler);
        $this->registry->add($method, '/secondary' . $path, [GatedAction::class, GatedAction::encode($mode, $handler, $this->module)]);
    }

    /** @param array{0: class-string, 1: string} $handler */
    private function addPlatform(string $method, string $path, array $handler): void
    {
        self::check($path, $handler);
        if ($this->module !== null) {
            throw new \LogicException('Une route de plateforme ne dépend d\'aucun module d\'établissement.');
        }
        $this->registry->add($method, '/platform/secondary' . $path, [PlatformAction::class, PlatformAction::encode($handler)], ['tenant' => false]);
    }

    /** @param array{0: class-string, 1: string} $handler */
    private static function check(string $path, array $handler): void
    {
        if (!str_starts_with($path, '/') || !str_starts_with($handler[0], self::NAMESPACE)) {
            throw new \LogicException('Route Secondary invalide : ' . $path);
        }
    }
}
