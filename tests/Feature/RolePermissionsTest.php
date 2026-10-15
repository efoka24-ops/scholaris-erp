<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;

/** Les permissions utilisées par les modules sont accordées aux bons rôles (et refusées aux autres). */
final class RolePermissionsTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $grants = [];

    protected function setUp(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        $rows = $kernel->container()->get(ConnectionFactory::class)->pdo()->query('SELECT role, permission FROM role_permissions')->fetchAll();
        foreach ($rows as $row) {
            $this->grants[(string) $row['role']][] = (string) $row['permission'];
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function expected(): array
    {
        return [
            'le caissier encaisse' => ['caissier', 'billing.cash'],
            'le caissier demande une annulation' => ['caissier', 'billing.reversal.request'],
            'le comptable valide une annulation' => ['comptable', 'billing.reversal.approve'],
            'le directeur gère les personnes' => ['directeur', 'people.manage'],
            'le directeur lit la santé' => ['directeur', 'people.health.read'],
            'la direction de groupe émet des documents' => ['direction_groupe', 'documents.issue'],
            'le super admin envoie des messages' => ['super_admin', 'messaging.send'],
            'le parent demande un document' => ['parent', 'documents.request'],
        ];
    }

    /** @dataProvider expected */
    public function testPermissionAccordee(string $role, string $permission): void
    {
        self::assertContains($permission, $this->grants[$role] ?? []);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function forbidden(): array
    {
        return [
            'l\'enseignant ne gère pas la facturation' => ['enseignant', 'billing.manage'],
            'l\'enseignant ne lit pas toutes les personnes' => ['enseignant', 'people.read'],
            'le caissier ne valide pas ses propres annulations' => ['caissier', 'billing.reversal.approve'],
            'le comptable ne lit pas la santé' => ['comptable', 'people.health.read'],
            'le parent ne lit pas la facturation du client' => ['parent', 'billing.read'],
            'l\'apprenant ne gère pas les personnes' => ['apprenant', 'people.manage'],
        ];
    }

    /** @dataProvider forbidden */
    public function testPermissionRefusee(string $role, string $permission): void
    {
        self::assertNotContains($permission, $this->grants[$role] ?? []);
    }
}
