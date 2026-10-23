<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/** T142 : le .htaccess racine interdit l'accès au code, aux secrets, aux sauvegardes et aux fichiers de configuration d'outils. */
final class WebRootProtectionTest extends TestCase
{
    public function testLesCheminsSensiblesSontInterditsParLaRacine(): void
    {
        $rules = (string) file_get_contents(dirname(__DIR__, 2) . '/.htaccess');
        preg_match_all('/^RewriteRule \^(.+?) - \[F,L\]$/m', $rules, $matches);
        self::assertNotEmpty($matches[1]);

        foreach (['src/X.php', 'storage/backups/scholaris-20261001-020000.sbk', 'config/app.php', 'vendor/autoload.php', 'web/package.json', '.phpunit.cache-mysql/x', '.env', '.env.production', 'artisan', 'composer.lock', 'phpunit.mysql.xml', 'phpstan.neon'] as $path) {
            $blocked = false;
            foreach ($matches[1] as $pattern) {
                if (preg_match('~^' . $pattern . '~', $path) === 1) {
                    $blocked = true;
                }
            }
            self::assertTrue($blocked, $path . ' devrait être interdit.');
        }
        foreach (['public/index.html', 'api/v1/health'] as $path) {
            foreach ($matches[1] as $pattern) {
                self::assertSame(0, preg_match('~^' . $pattern . '~', $path), $path . ' ne doit pas être interdit.');
            }
        }
    }
}
