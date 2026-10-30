<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Support;

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Apps\PublicSchools\PublicSchoolsApplication;

/**
 * Seul moyen d'enregistrer une route des écoles publiques. Le préfixe est `/public` (modules communs, par défaut) ou
 * `/public-primary` (Primaire publique ; `/primary` est réservé à l'application privée). Chaque route passe par GatedAction :
 * GET contrôle la lecture (application fermée = 404), POST/PUT/PATCH/DELETE contrôlent l'écriture par WriteGate (suspendue = 423).
 *
 * Un sous-module déclare ses routes dans `<Application>/<Module>/routes.php` :
 *     return static function (PublicRoutes $routes): void {
 *         $routes->post('/pupils', [PupilController::class, 'create']);
 *     };
 * Le fichier racine `routes.php` de l'application les inclut tous (glob) ; il n'est jamais modifié par les sous-modules.
 * Aucune route sans authentification ici : les routes ouvertes (vérification d'un document, USSD) relèvent du socle.
 */
final class PublicRoutes
{
    public const PREFIX = PublicSchoolsApplication::ROUTE_PREFIX;
    public const PRIMARY_PREFIX = '/public-primary';

    public function __construct(private readonly RouteRegistry $registry, private readonly string $prefix = self::PREFIX)
    {
        if (!in_array($prefix, [self::PREFIX, self::PRIMARY_PREFIX], true)) {
            throw new \LogicException('Préfixe de routes publiques inconnu : ' . $prefix);
        }
    }

    public function prefix(): string
    {
        return $this->prefix;
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
            throw new \LogicException('Une route des écoles publiques exige une authentification : ' . $method . ' ' . $path);
        }
        if (!str_starts_with($path, '/')) {
            throw new \LogicException('Chemin invalide : ' . $path);
        }
        if (!GatedAction::isPublicController($handler[0])) {
            throw new \LogicException('Contrôleur hors des écoles publiques : ' . $handler[0]);
        }
        $this->registry->add($method, $this->prefix . $path, [GatedAction::class, GatedAction::encode($mode, $handler)], $options);
    }
}
