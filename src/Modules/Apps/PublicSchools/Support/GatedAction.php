<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Support;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;

/**
 * Contrôleur frontal de toutes les routes des écoles publiques (enregistrées par PublicRoutes). Le nom de méthode appelé par le
 * noyau encode `read|write`, la classe du contrôleur et sa méthode ; la porte du droit d'usage (WriteGate) passe AVANT le
 * contrôleur : aucune écriture ne peut l'oublier. Seuls les contrôleurs de PublicSchools et de PublicPrimary sont acceptés ;
 * l'application exigée se déduit de l'espace de noms (PublicPrimary => `public_primary`, PublicSchools => l'une ou l'autre).
 */
final class GatedAction
{
    private const COMMON = 'Scholaris\\Modules\\Apps\\PublicSchools\\';
    private const PRIMARY = 'Scholaris\\Modules\\Apps\\PublicPrimary\\';

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly WriteGate $gate,
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
        if (count($parts) !== 3 || !in_array($parts[0], ['read', 'write'], true) || !self::isPublicController($parts[1])
            || !class_exists($parts[1]) || preg_match('/^[a-zA-Z_]\w*$/', $parts[2]) !== 1) {
            throw new \LogicException('Route des écoles publiques invalide : ' . $name);
        }
        /** @var class-string $class */
        $class = $parts[1];

        return [$parts[0], $class, $parts[2]];
    }

    /** Vrai si la classe appartient à PublicSchools ou à PublicPrimary. */
    public static function isPublicController(string $class): bool
    {
        return str_starts_with($class, self::COMMON) || str_starts_with($class, self::PRIMARY);
    }

    /** Application dont le droit d'usage est exigé : `public_primary` pour un contrôleur PublicPrimary, null (l'une des deux) sinon. */
    public static function applicationOf(string $class): ?string
    {
        return str_starts_with($class, self::PRIMARY) ? 'public_primary' : null;
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
            $this->gate->assertWritable($principal, self::applicationOf($class));
        } else {
            $this->gate->assertReadable($principal, self::applicationOf($class));
        }

        $response = $this->container->get($class)->{$method}($request);
        if (!$response instanceof ResponseInterface) {
            throw new \LogicException('Le contrôleur ' . $class . '::' . $method . ' doit rendre une réponse PSR-7.');
        }

        return $response;
    }
}
