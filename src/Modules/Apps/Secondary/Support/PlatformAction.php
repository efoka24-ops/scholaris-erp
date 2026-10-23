<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Support;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/**
 * Enveloppe des routes /platform/secondary/... (listes de référence tenues par la plateforme) : réservées à l'administrateur de
 * plateforme (client technique `platform`, jamais un compte de service), avec `platform.access` et
 * `platform.applications.manage`. Refus : 403, avant toute lecture du corps.
 */
final class PlatformAction
{
    public const PLATFORM_TENANT = 'platform';
    public const PERMISSIONS = ['platform.access', 'platform.applications.manage'];
    private const NAMESPACE = 'Scholaris\\Modules\\Apps\\Secondary\\';

    public function __construct(private readonly ContainerInterface $container, private readonly PermissionChecker $permissions)
    {
    }

    /** @param array{0: class-string, 1: string} $handler */
    public static function encode(array $handler): string
    {
        return 'platform|' . $handler[0] . '|' . $handler[1];
    }

    /** @return array{0: class-string, 1: string} */
    public static function decode(string $name): array
    {
        $parts = explode('|', $name);
        if (count($parts) !== 3 || $parts[0] !== 'platform' || !str_starts_with($parts[1], self::NAMESPACE) || !class_exists($parts[1])
            || preg_match('/^[a-zA-Z_]\\w*$/', $parts[2]) !== 1) {
            throw new \LogicException('Route de plateforme Secondary invalide : ' . $name);
        }
        /** @var class-string $class */
        $class = $parts[1];

        return [$class, $parts[2]];
    }

    public function assertPlatformAdmin(Principal $principal): void
    {
        if ($principal->tenantId !== self::PLATFORM_TENANT || str_starts_with($principal->userId, 'svc:')) {
            throw HttpException::forbidden('Réservé à l\'administrateur de plateforme.');
        }
        foreach (self::PERMISSIONS as $permission) {
            if (!$this->permissions->can($principal, $permission)) {
                throw HttpException::forbidden('Réservé à l\'administrateur de plateforme.');
            }
        }
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): ResponseInterface
    {
        [$class, $method] = self::decode($name);
        $request = $arguments[0] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            throw new \LogicException('Requête attendue.');
        }
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $this->assertPlatformAdmin($principal);
        $response = $this->container->get($class)->{$method}($request);
        if (!$response instanceof ResponseInterface) {
            throw new \LogicException('Le contrôleur Secondary doit rendre une réponse PSR-7.');
        }

        return $response;
    }
}
