<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Apps\Primary\PrimaryApplication;
use Scholaris\Modules\Apps\Primary\Support\GatedAction;

final class PrimaryConventionsTest extends TestCase
{
    public function testPrimaryManifestRemainsComingSoon(): void
    {
        self::assertSame('coming_soon', PrimaryApplication::manifest()->status);
    }

    public function testPrimaryApplicationDeclaresOnlyPrimaryPermissionPrefix(): void
    {
        foreach (PrimaryApplication::permissions() as $permission) {
            self::assertStringStartsWith('primary.', $permission);
        }
    }

    public function testGatedActionRejectsUnknownMethods(): void
    {
        $this->expectException(\LogicException::class);
        GatedAction::decode('delete|Scholaris\\Modules\\Apps\\Primary\\FakeController|remove');
    }

    public function testPrimaryCodeHasNoHardCodedGradeThresholds(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Modules/Apps/Primary'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('/(?:passMark|pass_mark|threshold)\\s*=\\s*\\d/', $source, $file->getPathname());
        }
    }
    public function testGradesDomainContainsNoNumericLiteralOtherThanZeroAndOne(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Modules/Apps/Primary/Grades/Domain'));
        $checked = 0;
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            ++$checked;
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_LNUMBER, T_DNUMBER], true)) {
                    self::assertContains((float) $token[1], [0.0, 1.0], 'Littéral numérique codé en dur dans ' . $file->getPathname() . ' : ' . $token[1] . ' (SC-PRI-4).');
                }
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    public function testEveryPrimaryTableCarriesTenantAndEstablishmentAndNoReservedWord(): void
    {
        $reserved = ['rank', 'order', 'groups', 'range', 'condition', 'key', 'start', 'end', 'read', 'usage', 'system', 'last_value', 'release', 'rows'];
        $tables = 0;
        foreach (glob(dirname(__DIR__, 2) . '/src/Modules/Apps/Primary/*/Database/Migrations/*.sql') ?: [] as $file) {
            $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
            preg_match_all('/CREATE TABLE (\w+) \((.*?)\n\);/s', $sql, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                ++$tables;
                self::assertStringStartsWith('pri_', $match[1]);
                self::assertStringContainsString('tenant_id VARCHAR(40) NOT NULL', $match[2], $match[1]);
                self::assertStringContainsString('establishment_id VARCHAR(40) NOT NULL', $match[2], $match[1]);
                preg_match_all('/^\s+(\w+)\s+(?:CHAR|VARCHAR|INTEGER|TEXT)/m', $match[2], $columns);
                self::assertSame([], array_intersect($columns[1], $reserved), 'Mot réservé dans ' . $match[1]);
            }
        }
        self::assertGreaterThanOrEqual(10, $tables);
    }

    public function testNoPrimaryMigrationPredatesTheSkeleton(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/src/Modules/Apps/Primary/*/Database/Migrations/*.sql') ?: [] as $file) {
            self::assertGreaterThan('202610211210', basename($file), basename($file));
        }
    }

    public function testEveryPrimaryRouteGoesThroughTheWriteGate(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['api_prefix' => '/api/v1', 'timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log'],
        ]));
        $seen = 0;
        foreach ($kernel->routes()->all() as $route) {
            if (!str_starts_with($route['path'], '/api/v1/primary/')) {
                continue;
            }
            ++$seen;
            self::assertSame(GatedAction::class, $route['handler'][0], $route['path']);
            [$mode] = GatedAction::decode($route['handler'][1]);
            self::assertSame($route['method'] === 'GET' ? 'read' : 'write', $mode, $route['method'] . ' ' . $route['path']);
        }
        self::assertGreaterThan(20, $seen);
    }
}
