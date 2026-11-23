<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Support\Performance\LatencyProbe;

/**
 * T141 (partie locale) : garde-fou de régression sur le coût applicatif d'une requête, en processus sur SQLite.
 * Ce n'est pas un test de charge k6 (voir scripts/loadtest/).
 */
final class LoadSmokeTest extends TestCase
{
    public function testLeP95EnProcessusResteSousLObjectifDe300Ms(): void
    {
        $probe = new LatencyProbe(300);

        foreach ([['/api/v1/health', false], ['/api/v1/_test/things', true]] as [$path, $authenticated]) {
            $result = $probe->measure($path, 120, $authenticated);

            self::assertSame(0, $result['errors'], $path);
            self::assertLessThan(300.0, $result['p95_ms'], $path . ' p95=' . $result['p95_ms'] . ' ms');
        }
    }
}
