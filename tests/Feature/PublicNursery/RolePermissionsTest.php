<?php

declare(strict_types=1);

namespace Tests\Feature\PublicNursery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;

/** T712 : les deux rôles propres à la Maternelle publique et leurs permissions `public_nursery.*`. */
final class RolePermissionsTest extends TestCase
{
    private const MIGRATION = '/database/migrations/202610161240_roles_public_nursery.sql';

    /** @var array<string, array{level: string, sensitive: bool, label_fr: string, label_en: string}> */
    private static array $roles = [];

    /** @var array<string, list<string>> */
    private static array $grants = [];

    protected function setUp(): void
    {
        if (self::$roles !== []) {
            return;
        }
        $kernel = Kernel::boot(dirname(__DIR__, 3), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        $pdo = $kernel->container()->get(ConnectionFactory::class)->pdo();
        foreach ((array) $pdo->query('SELECT code, level, is_sensitive, label_fr, label_en FROM roles')->fetchAll() as $row) {
            self::$roles[(string) $row['code']] = ['level' => (string) $row['level'], 'sensitive' => (int) $row['is_sensitive'] === 1, 'label_fr' => (string) $row['label_fr'], 'label_en' => (string) $row['label_en']];
        }
        foreach ((array) $pdo->query('SELECT role, permission FROM role_permissions')->fetchAll() as $row) {
            self::$grants[(string) $row['role']][] = (string) $row['permission'];
        }
    }

    /** @return array<string, array{0: string}> */
    public static function roles(): array
    {
        return [
            'pn_educator' => ['pn_educator'],
            'pn_assistant' => ['pn_assistant'],
        ];
    }

    #[DataProvider('roles')]
    public function testChaqueRoleExisteAvecSonNiveauEtSesLibelles(string $code): void
    {
        self::assertArrayHasKey($code, self::$roles);
        self::assertSame('class', self::$roles[$code]['level']);
        self::assertFalse(self::$roles[$code]['sensitive']);
        self::assertNotSame('', self::$roles[$code]['label_fr']);
        self::assertNotSame('', self::$roles[$code]['label_en']);
    }

    #[DataProvider('roles')]
    public function testChaqueRoleALesPermissionsExactes(string $code): void
    {
        $expected = $code === 'pn_educator'
            ? ['public_nursery.pickup.handover', 'public_nursery.dev.observe', 'public_nursery.lifebook.write', 'public_nursery.need.report', 'public_nursery.transition.opinion']
            : ['public_nursery.pickup.handover', 'public_nursery.arrival.record'];
        $granted = self::$grants[$code] ?? [];
        sort($expected);
        sort($granted);

        self::assertSame($expected, $granted);
    }

    public function testSeulsLesDeuxRolesMaternelleSontSemes(): void
    {
        $codes = array_values(array_filter(array_keys(self::$roles), static fn (string $code): bool => str_starts_with($code, 'pn_')));
        sort($codes);

        self::assertSame(['pn_assistant', 'pn_educator'], $codes);
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . self::MIGRATION);
        self::assertStringNotContainsString('parent', $sql, 'R14 n\'est pas un compte.');
    }
}
