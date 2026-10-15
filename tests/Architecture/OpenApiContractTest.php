<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Kernel;
use Symfony\Component\Yaml\Yaml;

/** Test de contrat : toute route enregistrée par le cœur ou les modules figure dans le document OpenAPI. */
final class OpenApiContractTest extends TestCase
{
    public function testToutesLesRoutesSontDocumentees(): void
    {
        $root = dirname(__DIR__, 2);
        $spec = Yaml::parseFile($root . '/specs/001-socle-plateforme/contracts/openapi.yaml');
        foreach (glob($root . '/specs/001-socle-plateforme/contracts/openapi.d/*.yaml') ?: [] as $fragment) {
            $spec['paths'] = array_merge($spec['paths'], (array) (Yaml::parseFile($fragment)['paths'] ?? []));
        }
        $kernel = Kernel::boot($root, Config::fromArray([
            'app' => ['api_prefix' => '/api/v1', 'timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log'],
        ]));

        $missing = [];
        foreach ($kernel->routes()->all() as $route) {
            $method = strtolower($route['method']);
            if (!isset($spec['paths'][$route['path']][$method])) {
                $missing[] = strtoupper($method) . ' ' . $route['path'];
            }
        }

        self::assertSame([], $missing, 'Routes absentes de contracts/openapi.yaml.');
        self::assertSame('3.1.0', $spec['openapi']);
    }
}
