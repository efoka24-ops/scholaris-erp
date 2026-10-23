<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Support;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;

/**
 * Enveloppe de TOUTE route /secondary (SecondaryRoutes) : porte du droit d'usage (WriteGate : 423 suspendu, 403 fermé, 404 en
 * lecture si fermé), PUIS garde de module pour une route d'un module activable (ModuleGate : 409 `module_not_enabled`), PUIS le
 * contrôleur (permission et périmètre). Le nom de la méthode appelée encode « mode|classe|méthode[|module] ».
 */
final class GatedAction
{
    private const NAMESPACE = 'Scholaris\\Modules\\Apps\\Secondary\\';

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly WriteGate $gate,
        private readonly ModuleGate $modules,
    ) {
    }

    /** @param array{0: class-string, 1: string} $handler */
    public static function encode(string $mode, array $handler, ?string $module = null): string
    {
        return $mode . '|' . $handler[0] . '|' . $handler[1] . ($module === null ? '' : '|' . $module);
    }

    /** @return array{0: string, 1: class-string, 2: string, 3: ?string} mode, classe, méthode, module activable (ou null : noyau) */
    public static function decode(string $name): array
    {
        $parts = explode('|', $name);
        $count = count($parts);
        if (($count !== 3 && $count !== 4) || !in_array($parts[0], ['read', 'write'], true) || !str_starts_with($parts[1], self::NAMESPACE)
            || !class_exists($parts[1]) || preg_match('/^[a-zA-Z_]\\w*$/', $parts[2]) !== 1
            || ($count === 4 && !in_array($parts[3], Modules::ALL, true))) {
            throw new \LogicException('Route Secondary invalide : ' . $name);
        }
        /** @var class-string $class */
        $class = $parts[1];

        return [$parts[0], $class, $parts[2], $parts[3] ?? null];
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): ResponseInterface
    {
        [$mode, $class, $method, $module] = self::decode($name);
        $request = $arguments[0] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            throw new \LogicException('Requête attendue.');
        }
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $mode === 'write' ? $this->gate->assertWritable($principal) : $this->gate->assertReadable($principal);
        if ($module !== null) {
            $this->modules->assertAvailable($principal->tenantId, Access::establishmentId($request), $module, $mode === 'write');
        }
        $response = $this->container->get($class)->{$method}($request);
        if (!$response instanceof ResponseInterface) {
            throw new \LogicException('Le contrôleur Secondary doit rendre une réponse PSR-7.');
        }

        return $response;
    }
}
