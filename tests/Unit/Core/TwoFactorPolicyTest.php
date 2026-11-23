<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Identity\Application\TwoFactorPolicy;

final class TwoFactorPolicyTest extends TestCase
{
    public function testExigeeParDefaut(): void
    {
        self::assertTrue((new TwoFactorPolicy(Config::fromArray([])))->enforced());
    }

    public function testPeutEtreeDesactiveeParLaConfiguration(): void
    {
        foreach ([false, 'false', '0', 0] as $value) {
            self::assertFalse((new TwoFactorPolicy(Config::fromArray(['app' => ['two_factor_required' => $value]])))->enforced());
        }
        self::assertTrue((new TwoFactorPolicy(Config::fromArray(['app' => ['two_factor_required' => 'true']])))->enforced());
    }
}
