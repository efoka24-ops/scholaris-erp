<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Apps\Higher\HigherApplication;
use Scholaris\Modules\Apps\Higher\Policies\RoleMatrix;

/**
 * Migration partagée des rôles R01-R23 (database/migrations/202610109000_roles_higher.sql) : chaque rôle existe avec ses
 * permissions minimales, les rôles créés ont exactement celles de RoleMatrix, les rôles du socle gardent les leurs.
 */
final class HigherRolesMigrationTest extends TestCase
{
    /** @var array<string, array{level: string, sensitive: bool}>|null */
    private static ?array $roles = null;

    /** @var array<string, list<string>> */
    private static array $grants = [];

    protected function setUp(): void
    {
        if (self::$roles !== null) {
            return;
        }
        $kernel = Kernel::boot(dirname(__DIR__, 4), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        $pdo = $kernel->container()->get(ConnectionFactory::class)->pdo();
        self::$roles = [];
        foreach ((array) $pdo->query('SELECT code, level, is_sensitive FROM roles')->fetchAll() as $row) {
            self::$roles[(string) $row['code']] = ['level' => (string) $row['level'], 'sensitive' => (int) $row['is_sensitive'] === 1];
        }
        foreach ((array) $pdo->query('SELECT role, permission FROM role_permissions')->fetchAll() as $row) {
            self::$grants[(string) $row['role']][] = (string) $row['permission'];
        }
    }

    public function testLesVingtTroisRolesSontDeclares(): void
    {
        self::assertSame(array_map(static fn (int $i): string => sprintf('R%02d', $i), range(1, 23)), array_keys(RoleMatrix::ROLES));
    }

    public function testChaqueRoleExisteAvecSesPermissionsMinimales(): void
    {
        foreach (RoleMatrix::ROLES as $number => $role) {
            $granted = self::$grants[$role['code']] ?? [];

            self::assertArrayHasKey($role['code'], self::$roles, $number . ' absent de la table roles');
            self::assertSame($role['level'], self::$roles[$role['code']]['level'], $number);
            self::assertSame($role['sensitive'], self::$roles[$role['code']]['sensitive'], $number . ' : sensibilité 2FA (decisions.md R33)');
            self::assertSame([], array_values(array_diff($role['permissions'], $granted)), $number . ' : permissions manquantes');
            if ($role['new']) {
                $expected = $role['permissions'];
                sort($expected);
                sort($granted);
                self::assertSame($expected, $granted, $number . ' : la migration et RoleMatrix doivent concorder');
            }
        }
    }

    public function testLesPermissionsExistantesDuSocleSontReutilisees(): void
    {
        self::assertContains('assessment.deliberation.sign', self::$grants['higher_jury'] ?? [], 'R21 signe le PV par la permission du socle');
        self::assertContains('assessment.reopen', self::$grants['higher_dir_academique'] ?? [], 'R03 rouvre une délibération signée');
        self::assertContains('assessment.correction.approve', self::$grants['higher_dir_ecole'] ?? []);
        self::assertContains('billing.cash', self::$grants['caissier'] ?? []);
        self::assertSame([], self::$grants['higher_biblio'] ?? [], 'R18 : déclaré sans permission');
    }

    /** @return array<string, array{0: string}> */
    public static function selfOnly(): array
    {
        return ['R12 étudiant' => ['apprenant'], 'R13 garant' => ['parent']];
    }

    #[DataProvider('selfOnly')]
    public function testEtudiantEtGarantNOntAucunePermissionDeGestion(string $code): void
    {
        // Les permissions « self » (self.read, billing.self.pay…) portent sur la personne elle-même : elles ne gèrent rien.
        $offenders = array_filter(
            self::$grants[$code] ?? [],
            static fn (string $p): bool => !in_array('self', explode('.', $p), true)
                && (in_array(substr($p, (int) strrpos($p, '.') + 1), RoleMatrix::MANAGEMENT_VERBS, true) || str_ends_with($p, '.read')),
        );

        self::assertSame([], array_values($offenders));
    }

    public function testLesRolesExternesSontEnLectureSeuleSurDesPermissionsHigherFiltrees(): void
    {
        foreach (['R22', 'R23'] as $number) {
            $role = RoleMatrix::ROLES[$number];
            self::assertTrue($role['external']);
            self::assertSame(90, $role['max_days'], $number . ' : affectation à durée limitée (decisions.md R31)');
            foreach (self::$grants[$role['code']] ?? [] as $permission) {
                self::assertStringStartsWith('higher.', $permission, $number . ' : aucune permission du socle (non filtrée par périmètre)');
                self::assertTrue($permission === 'higher.access' || str_ends_with($permission, '.read'), $number . ' : ' . $permission . ' n\'est pas une lecture');
            }
        }
    }

    public function testLesHuitRolesDuSocleSontIntacts(): void
    {
        foreach (['super_admin', 'direction_groupe', 'directeur', 'comptable', 'caissier', 'enseignant', 'parent', 'apprenant'] as $code) {
            self::assertArrayHasKey($code, self::$roles);
        }
        self::assertSame('tenant', self::$roles['direction_groupe']['level']);
        self::assertNotContains('billing.manage', self::$grants['enseignant']);
        self::assertNotContains('people.read', self::$grants['enseignant']);
        foreach (['enseignant', 'parent', 'apprenant', 'caissier', 'comptable', 'direction_groupe', 'directeur', 'super_admin'] as $code) {
            $added = array_filter(self::$grants[$code], static fn (string $p): bool => str_starts_with($p, 'higher.'));
            self::assertContains('higher.access', $added, $code);
        }
    }

    public function testLeManifesteDeclareLesRolesEtLesPermissionsDeLApplication(): void
    {
        self::assertContains('higher_tutelle', HigherApplication::roles());
        self::assertContains('higher.access', HigherApplication::permissions());
        foreach (HigherApplication::permissions() as $permission) {
            self::assertLessThanOrEqual(60, strlen($permission), 'role_permissions.permission est un VARCHAR(60)');
            self::assertMatchesRegularExpression('/^higher\.[a-z_]+(\.[a-z_]+)*$/', $permission);
        }
    }
}
