<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Support;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;

/**
 * Contrôleur frontal de toutes les routes de la Maternelle publique (enregistrées par NurseryRoutes). Le nom de méthode appelé par
 * le noyau encode `read|write`, la classe du contrôleur et sa méthode ; la porte du droit d'usage (NurseryWriteGate) passe AVANT
 * le contrôleur : aucune écriture ne peut l'oublier (suspendu : 423, fermé : 403) et une application fermée n'est pas lisible (404).
 * Seuls les contrôleurs du module PublicNursery sont acceptés.
 */
final class GatedAction
{
    private const NAMESPACE_PREFIX = 'Scholaris\\Modules\\Apps\\PublicNursery\\';

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly NurseryWriteGate $gate,
    ) {
    }

    /** @param array{0: class-string, 1: string} $handler */
    public static function encode(string $mode, array $handler): string
    {
        return $mode . '|' . $handler[0] . '|' . $handler[1];
    }

    /** @return array{0: string, 1: class-string, 2: string} */
    public static function decode(string $name): array
    {
        $parts = explode('|', $name);
        if (count($parts) !== 3 || !in_array($parts[0], ['read', 'write'], true) || !str_starts_with($parts[1], self::NAMESPACE_PREFIX)
            || !class_exists($parts[1]) || preg_match('/^[a-zA-Z_]\w*$/', $parts[2]) !== 1) {
            throw new \LogicException('Route de la Maternelle publique invalide : ' . $name);
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): ResponseInterface
    {
        [$mode, $class, $method] = self::decode($name);
        $request = $arguments[0] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            throw new \LogicException('Requête attendue.');
        }
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        if ($mode === 'write') {
            $this->gate->assertWritable($principal);
        } else {
            $this->gate->assertReadable($principal);
        }

        $response = $this->container->get($class)->{$method}($request);
        if (!$response instanceof ResponseInterface) {
            throw new \LogicException('Le contrôleur ' . $class . '::' . $method . ' doit rendre une réponse PSR-7.');
        }

        return $response;
    }
}
