<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
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
}
