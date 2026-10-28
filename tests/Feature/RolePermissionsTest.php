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

    /** @var array<string, array{level: string, sensitive: bool}> */
    private array $roles = [];

    protected function setUp(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        $pdo = $kernel->container()->get(ConnectionFactory::class)->pdo();
        foreach ($pdo->query('SELECT code, level, is_sensitive FROM roles')->fetchAll() as $row) {
            $this->roles[(string) $row['code']] = ['level' => (string) $row['level'], 'sensitive' => (int) $row['is_sensitive'] === 1];
        }
        $rows = $pdo->query('SELECT role, permission FROM role_permissions')->fetchAll();
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

    /** @return array<string, array{0: string, 1: bool, 2: list<string>}> les 5 rôles de 202610201201_roles_primary.sql */
    public static function primaryRoles(): array
    {
        return [
            // P-002 (migration 202610231411) : conseil, bulletins, remédiation, examen, tutelle, services, sortie sécurisée (lecture).
            'primary_promoteur' => ['primary_promoteur', false, ['primary.dashboard.read', 'reporting.read', 'primary.referential.read', 'primary.learner.read', 'primary.competency.read', 'primary.conduct.read', 'primary.journal.read',
                'primary.council.read', 'primary.report_card.read', 'primary.remediation.read', 'primary.exam.read', 'primary.tutelage.export', 'primary.services.read']],
            'primary_directeur' => ['primary_directeur', true, ['primary.settings.manage', 'primary.council.preside', 'primary.report_card.sign', 'assessment.correction.approve', 'primary.promotion.decide', 'primary.dashboard.read', 'primary.referential.read', 'primary.learner.read', 'primary.competency.read', 'primary.competency.validate', 'primary.conduct.read', 'primary.journal.read',
                'primary.council.read', 'primary.report_card.read', 'primary.remediation.read', 'primary.remediation.write', 'primary.exam.read', 'primary.exam.manage', 'primary.tutelage.export', 'primary.services.read', 'primary.pickup.read', 'primary.pickup.handover']],
            'primary_adjoint' => ['primary_adjoint', false, ['primary.referential.manage', 'assessment.validate', 'scheduling.manage', 'primary.referential.read', 'primary.learner.read', 'primary.competency.read', 'primary.competency.validate', 'primary.conduct.read', 'primary.journal.read',
                'primary.council.read', 'primary.report_card.read', 'primary.remediation.read', 'primary.remediation.write', 'primary.exam.read']],
            'primary_secretaire' => ['primary_secretaire', false, ['people.manage', 'primary.enrol', 'documents.issue', 'primary.exam.manage', 'primary.referential.read', 'primary.learner.read',
                'primary.report_card.read', 'primary.exam.read', 'primary.tutelage.export', 'primary.services.read']],
            'primary_surveillant' => ['primary_surveillant', false, ['attendance.sanction.manage', 'primary.pickup.handover', 'primary.pickup.read']],
        ];
    }

    /**
     * @dataProvider primaryRoles
     * @param list<string> $permissions
     */
    public function testRolePrimaireAvecSesPermissionsExactes(string $role, bool $sensitive, array $permissions): void
    {
        $granted = $this->grants[$role] ?? [];
        sort($granted);
        sort($permissions);

        self::assertSame($permissions, $granted);
    }

    /** @dataProvider primaryRoles */
    public function testRolePrimaireDeNiveauEtablissementEtSaSensibilite(string $role, bool $sensitive): void
    {
        self::assertArrayHasKey($role, $this->roles);
        self::assertSame('establishment', $this->roles[$role]['level']);
        self::assertSame($sensitive, $this->roles[$role]['sensitive']);
    }

    public function testSeulsLesCinqRolesPrimaireSontSemesEtLeDirecteurEstLeSeulSensible(): void
    {
        $codes = array_values(array_filter(array_keys($this->roles), static fn (string $code): bool => str_starts_with($code, 'primary_')));
        sort($codes);
        self::assertSame(['primary_adjoint', 'primary_directeur', 'primary_promoteur', 'primary_secretaire', 'primary_surveillant'], $codes);
        $sensitive = array_values(array_filter($codes, fn (string $code): bool => $this->roles[$code]['sensitive']));
        self::assertSame(['primary_directeur'], $sensitive);
    }

    public function testLesPermissionsSensiblesDuPrimaireNeSontPasAccordeesAuxAutresRolesPrimaire(): void
    {
        foreach (['primary.report_card.sign', 'primary.promotion.decide', 'primary.council.preside', 'assessment.correction.approve'] as $permission) {
            foreach (['primary_promoteur', 'primary_adjoint', 'primary_secretaire', 'primary_surveillant'] as $role) {
                self::assertNotContains($permission, $this->grants[$role] ?? [], $role . ' ne doit pas avoir ' . $permission);
            }
        }
    }
}
