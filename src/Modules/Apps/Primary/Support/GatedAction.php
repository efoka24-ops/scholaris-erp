<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;

final class GatedAction
{
    private const NAMESPACE = 'Scholaris\\Modules\\Apps\\Primary\\';

    public function __construct(private readonly ContainerInterface $container, private readonly WriteGate $gate)
    {
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
        if (count($parts) !== 3 || !in_array($parts[0], ['read', 'write'], true) || !str_starts_with($parts[1], self::NAMESPACE)
            || !class_exists($parts[1]) || preg_match('/^[a-zA-Z_]\\w*$/', $parts[2]) !== 1) {
            throw new \LogicException('Route Primary invalide : ' . $name);
        }
        /** @var class-string $class */
        $class = $parts[1];

        return [$parts[0], $class, $parts[2]];
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
        $mode === 'write' ? $this->gate->assertWritable($principal) : $this->gate->assertReadable($principal);
        $response = $this->container->get($class)->{$method}($request);
        if (!$response instanceof ResponseInterface) {
            throw new \LogicException('Le contrôleur Primary doit rendre une réponse PSR-7.');
        }

        return $response;
    }
}
