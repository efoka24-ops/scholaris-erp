<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Kernel;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Organisation\Http\Policies\TerritoryPolicy;

/**
 * PP18 / T610 : rôles R01-R10, R13, R15-R21 des écoles publiques et leurs permissions (plan.md §5), semés par la migration
 * `database/migrations/202610152351_roles_public_schools.sql`. R11, R12 et R14 sont propres à la Maternelle (T712) et absents ici.
 * Les rôles de supervision `aggregate_only` sont introduits par PP2 (T606), pas par cette migration.
 */
final class RolePermissionsPublicSchoolsTest extends TestCase
{
    private const MIGRATION = '/database/migrations/202610152351_roles_public_schools.sql';

    /**
     * code => [numéro CDC, niveau, sensible (2FA), permissions].
     *
     * @var array<string, array{0: string, 1: string, 2: bool, 3: list<string>}>
     */
    private const MATRIX = [
        'pub_cabinet' => ['R01', 'tenant', false, ['public_schools.aggregates.read']],
        'pub_exam_directorate' => ['R02', 'tenant', true, ['public_schools.aggregates.read', 'public_primary.exams.consolidate', 'public_primary.exams.import']],
        'pub_planning' => ['R03', 'tenant', false, ['public_schools.aggregates.read', 'public_schools.census.validate']],
        'pub_inspection_general' => ['R04', 'tenant', true, ['public_schools.aggregates.read', 'public_schools.inspection.read', 'documents.templates.manage']],
        'pub_regional_delegate' => ['R05', 'territory', true, ['public_schools.school.read', 'public_schools.aggregates.read', 'public_schools.census.validate', 'public_schools.messages.announce_territory']],
        'pub_departmental_delegate' => ['R06', 'territory', true, [
            'public_schools.school.read', 'public_schools.pupils.read', 'public_schools.pupils.named.read', 'public_schools.staff.read', 'public_schools.inspection.read',
            'public_schools.aggregates.read', 'public_schools.census.validate', 'public_schools.messages.send', 'public_schools.messages.announce_territory',
            'public_primary.exams.validate', 'public_primary.exams.import',
        ]],
        'pub_district_inspector' => ['R07', 'territory', true, [
            'public_schools.school.read', 'public_schools.school.validate', 'public_schools.pupils.read', 'public_schools.pupils.named.read',
            'public_schools.teacher_presence.read', 'public_schools.teacher_presence.validate', 'public_schools.staff.read', 'public_schools.messages.send',
            'public_schools.messages.announce_territory', 'public_schools.apee.read', 'public_schools.census.validate', 'public_schools.inspection.read',
            'public_schools.inspection.write', 'public_schools.aggregates.read', 'public_primary.promotion.opinion', 'public_primary.exams.validate',
            'organisation.read', 'sync.batches.submit',
        ]],
        'pub_pedagogical_advisor' => ['R08', 'territory', false, ['public_schools.school.read', 'public_schools.inspection.read', 'public_schools.inspection.write']],
        'pub_school_director' => ['R09', 'establishment', true, [
            'public_schools.school.read', 'public_schools.school.write', 'public_schools.pupils.read', 'public_schools.pupils.write',
            'public_schools.attendance.read', 'public_schools.attendance.write', 'public_schools.teacher_presence.read', 'public_schools.teacher_presence.write',
            'public_schools.staff.read', 'public_schools.staff.write', 'public_schools.messages.send', 'public_schools.apee.read', 'public_schools.census.write',
            'public_schools.inspection.read', 'public_primary.evaluation.validate', 'public_primary.evaluation.publish', 'public_primary.evaluation.rectify',
            'public_primary.promotion.validate', 'public_primary.exams.write', 'organisation.read', 'sync.batches.submit', 'sync.devices.manage',
        ]],
        'pub_class_teacher' => ['R10', 'establishment', true, [
            'public_schools.school.read', 'public_schools.pupils.read', 'public_schools.attendance.read', 'public_schools.attendance.write',
            'public_schools.teacher_presence.read', 'public_schools.messages.send', 'public_schools.inspection.read', 'public_primary.evaluation.write',
            'public_primary.promotion.write', 'organisation.read', 'sync.batches.submit',
        ]],
        'pub_apee_board' => ['R15', 'establishment', true, ['public_schools.school.read', 'public_schools.apee.read', 'public_schools.apee.write', 'public_schools.apee.read_receipts', 'public_schools.apee.non_payers.read']],
        'pub_school_council' => ['R16', 'establishment', false, ['public_schools.school.read', 'public_schools.apee.read']],
        'pub_canteen_manager' => ['R17', 'establishment', false, ['public_schools.school.read', 'public_schools.canteen.write']],
        'pub_school_health_agent' => ['R18', 'territory', false, ['public_schools.school.read', 'public_schools.canteen.write']],
        'pub_technical_partner' => ['R19', 'territory', false, ['public_schools.aggregates.read']],
        'pub_inspection_clerk' => ['R20', 'territory', true, ['public_schools.school.read', 'public_schools.pupils.read', 'public_schools.pupils.write', 'public_schools.census.write']],
        'pub_functional_admin' => ['R21', 'tenant', true, [
            'organisation.read', 'organisation.territories.manage', 'identity.manage', 'identity.roles.assign', 'settings.manage',
            'documents.templates.manage', 'messaging.templates.manage', 'sync.devices.manage',
        ]],
    ];

    /**
     * Permissions du socle accordées par des migrations ultérieures : permission => rôles.
     *
     * @var array<string, list<string>>
     */
    private const LATER_GRANTS = [
        'reporting.territory_aggregates.read' => [
            'pub_cabinet', 'pub_exam_directorate', 'pub_planning', 'pub_inspection_general', 'pub_regional_delegate',
            'pub_departmental_delegate', 'pub_district_inspector', 'pub_technical_partner',
        ],
        // Identity 202610201010 (PP3, T607) : journal des consultations nominatives visible du directeur.
        'identity.named_access.read' => ['pub_school_director'],
        // database/migrations/202610211104_roles_public_attendance.sql (T634, RG-PRE-1) : correction de l'appel par le directeur.
        'attendance.correct' => ['pub_school_director'],
        'attendance.correct_late' => ['pub_school_director'],
        // database/migrations/202610231301_roles_public_primary_p2.sql (lot P2) : référentiel national, téléchargement des PDF émis.
        'public_primary.referential.manage' => ['pub_inspection_general', 'pub_functional_admin'],
        'documents.read' => ['pub_school_director', 'pub_apee_board'],
        // database/migrations/202610231380_roles_public_nursery_director.sql : section maternelle d'un groupe scolaire.
        'public_nursery.contribution.manage' => ['pub_school_director'],
        'public_nursery.register.read' => ['pub_school_director'],
    ];

    /** @var array<string, array{level: string, sensitive: bool, label_fr: string, label_en: string}> */
    private static array $roles = [];

    /** @var array<string, list<string>> */
    private static array $grants = [];

    protected function setUp(): void
    {
        if (self::$roles !== []) {
            return;
        }
        $kernel = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
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
        $cases = [];
        foreach (self::MATRIX as $code => [$number]) {
            $cases[$number . ' ' . $code] = [$code];
        }

        return $cases;
    }

    #[DataProvider('roles')]
    public function testChaqueRoleExisteAvecSonNiveauSaSensibiliteEtSesLibelles(string $code): void
    {
        [$number, $level, $sensitive] = self::MATRIX[$code];

        self::assertArrayHasKey($code, self::$roles, $number . ' absent de la table roles');
        self::assertSame($level, self::$roles[$code]['level'], $number . ' : niveau');
        self::assertSame($sensitive, self::$roles[$code]['sensitive'], $number . ' : sensibilité 2FA');
        self::assertNotSame('', self::$roles[$code]['label_fr']);
        self::assertNotSame('', self::$roles[$code]['label_en']);
        self::assertLessThanOrEqual(40, strlen($code), 'roles.code est un VARCHAR(40)');
    }

    #[DataProvider('roles')]
    public function testChaqueRoleALesPermissionsExactesDuPlan(string $code): void
    {
        $expected = self::MATRIX[$code][3];
        foreach (self::LATER_GRANTS as $permission => $holders) {
            if (in_array($code, $holders, true)) {
                $expected[] = $permission;
            }
        }
        $granted = self::$grants[$code] ?? [];
        sort($expected);
        sort($granted);

        self::assertSame($expected, $granted, self::MATRIX[$code][0] . ' : permissions semées différentes du plan');
    }

    public function testSeulsLesDixSeptRolesPublicsSontCreesEtAucunRoleDeLaMaternelle(): void
    {
        $created = array_values(array_filter(array_keys(self::$roles), static fn (string $code): bool => str_starts_with($code, 'pub_')));
        $expected = array_keys(self::MATRIX);
        sort($created);
        sort($expected);
        $expected = array_values(array_filter($expected, static fn (string $code): bool => $code !== 'parent'));

        self::assertSame($expected, $created);
        self::assertCount(17, $created, 'R01-R10 et R15-R21 : dix-sept rôles propres ; R13 réutilise le rôle « parent » du socle.');
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . self::MIGRATION);
        self::assertStringNotContainsString('pn_', $sql, 'R11, R12 et R14 sont semés par T712 (Maternelle).');
        self::assertStringNotContainsString('public_nursery.', $sql);
        self::assertStringNotContainsString('aggregate_only', $sql, 'Les rôles de supervision aggregate_only relèvent de PP2 (T606).');
    }

    public function testR13ReutiliseLeRoleParentSansPerdreSesPermissions(): void
    {
        $parent = self::$grants['parent'] ?? [];

        self::assertContains('self.read', $parent);
        self::assertContains('documents.request', $parent);
        self::assertContains('public_schools.apee.read_receipts', $parent, 'R13 lit ses reçus APEE.');
        $public = array_values(array_filter($parent, static fn (string $p): bool => str_starts_with($p, 'public_')));
        self::assertSame(['public_schools.apee.read_receipts'], $public, 'R13 : aucune autre permission des écoles publiques.');
    }

    public function testLesRolesDuSocleNeGagnentAucunePermissionPublique(): void
    {
        foreach (['super_admin', 'direction_groupe', 'directeur', 'comptable', 'caissier', 'enseignant', 'apprenant'] as $code) {
            $public = array_filter(self::$grants[$code] ?? [], static fn (string $p): bool => str_starts_with($p, 'public_') || $p === 'organisation.territories.manage' || str_starts_with($p, 'sync.'));
            self::assertSame([], array_values($public), $code);
        }
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function holders(): array
    {
        return [
            'liste nominative des non-contributeurs : président et trésorier seulement (RG-APE-2)' => ['public_schools.apee.non_payers.read', ['pub_apee_board']],
            'consultation nominative d\'un élève hors école : délégué départemental et inspecteur seulement (RG-TER-2, RG-TER-3)' => ['public_schools.pupils.named.read', ['pub_departmental_delegate', 'pub_district_inspector']],
            'droit de rattacher une école à un territoire : administrateur fonctionnel seulement' => ['organisation.territories.manage', ['pub_functional_admin']],
            'écriture des encaissements APEE : bureau de l\'APEE seulement' => ['public_schools.apee.write', ['pub_apee_board']],
            'import des résultats d\'examen (API-10) : R02 et R06' => ['public_primary.exams.import', ['pub_departmental_delegate', 'pub_exam_directorate']],
            'validation d\'une liste de candidats (API-09) : R06 et R07' => ['public_primary.exams.validate', ['pub_departmental_delegate', 'pub_district_inspector']],
            'publication d\'un bulletin (API-07) : directeur seulement' => ['public_primary.evaluation.publish', ['pub_school_director']],
            'rectificatif d\'une mention : directeur seul (RG-EVA-3)' => ['public_primary.evaluation.rectify', ['pub_school_director']],
            'saisie des critères : titulaire de classe seulement' => ['public_primary.evaluation.write', ['pub_class_teacher']],
            'avis de l\'inspecteur au deuxième redoublement (RG-PAS-2)' => ['public_primary.promotion.opinion', ['pub_district_inspector']],
            'lot hors ligne (API-04) : R07, R09, R10' => ['sync.batches.submit', ['pub_class_teacher', 'pub_district_inspector', 'pub_school_director']],
            'gestion des appareils : directeur et administrateur fonctionnel' => ['sync.devices.manage', ['pub_functional_admin', 'pub_school_director']],
        ];
    }

    /**
     * @param list<string> $expectedRoles
     */
    #[DataProvider('holders')]
    public function testSeulsCesRolesDetiennentLaPermission(string $permission, array $expectedRoles): void
    {
        $holders = [];
        foreach (self::$grants as $role => $permissions) {
            if (in_array($permission, $permissions, true)) {
                $holders[] = $role;
            }
        }
        sort($holders);
        sort($expectedRoles);

        self::assertSame($expectedRoles, $holders);
    }

    public function testLesEnseignantsOntLEcritureDeLAppelMaisAucunAccesApeeNiNominatif(): void
    {
        $teacher = self::$grants['pub_class_teacher'];

        self::assertContains('public_schools.attendance.write', $teacher);
        foreach (['public_schools.apee.read', 'public_schools.apee.write', 'public_schools.apee.read_receipts', 'public_schools.apee.non_payers.read', 'public_schools.pupils.named.read', 'public_schools.pupils.write'] as $forbidden) {
            self::assertNotContains($forbidden, $teacher, $forbidden);
        }
        foreach (['enseignant', 'pub_class_teacher', 'pub_school_director'] as $role) {
            self::assertNotContains('public_schools.apee.non_payers.read', self::$grants[$role], $role . ' ne voit jamais la liste des non-contributeurs.');
        }
    }

    public function testLesRolesAgregatsSeulsNOntNiEcritureNiAccesNominatif(): void
    {
        foreach (['pub_cabinet', 'pub_planning', 'pub_technical_partner', 'pub_regional_delegate', 'pub_exam_directorate'] as $role) {
            foreach (self::$grants[$role] as $permission) {
                self::assertDoesNotMatchRegularExpression('/\.named\.|\.pupils\.|\.write$|\.attendance\.|\.apee\.|\.teacher_presence\./', $permission, $role . ' : ' . $permission . ' n\'est pas un agrégat ou une lecture d\'école');
            }
        }
        foreach (['pub_cabinet', 'pub_technical_partner'] as $role) {
            $grants = self::$grants[$role];
            sort($grants);
            self::assertSame(['public_schools.aggregates.read', 'reporting.territory_aggregates.read'], $grants, $role);
        }
        self::assertNotContains('public_schools.pupils.read', self::$grants['pub_regional_delegate'], 'RG-TER-3 : la région ne voit que des agrégats.');
    }

    public function testLesRolesDeCantineEtDeSanteNeVoientAucuneDonneeNominative(): void
    {
        foreach (['pub_canteen_manager', 'pub_school_health_agent', 'pub_school_council'] as $role) {
            $names = implode(' ', self::$grants[$role]);
            self::assertStringNotContainsString('pupils', $names, $role);
            self::assertStringNotContainsString('attendance', $names, $role);
        }
    }

    public function testLesNiveauxSuiventLaStructureTerritoriale(): void
    {
        $levels = [];
        foreach (self::MATRIX as $code => [$number, $level]) {
            $levels[$level][] = $number;
        }

        self::assertSame(['R01', 'R02', 'R03', 'R04', 'R21'], $levels['tenant'], 'National : niveau client.');
        self::assertSame(['R05', 'R06', 'R07', 'R08', 'R18', 'R19', 'R20'], $levels['territory'], 'Territorial : périmètre territoire (PP2).');
        self::assertSame(['R09', 'R10', 'R15', 'R16', 'R17'], $levels['establishment'], 'École : périmètre établissement.');
    }

    public function testToutesLesPermissionsRespectentLesLimitesDeColonneEtLeFormat(): void
    {
        foreach (self::MATRIX as $code => [, , , $permissions]) {
            foreach ($permissions as $permission) {
                self::assertLessThanOrEqual(60, strlen($permission), 'role_permissions.permission est un VARCHAR(60)');
                self::assertMatchesRegularExpression('/^[a-z_]+(\.[a-z_]+)+$/', $permission, $code);
            }
            self::assertSame(array_values(array_unique($permissions)), $permissions, $code . ' : permission en double');
        }
    }

    public function testLAdministrateurFonctionnelPeutRattacherUneEcoleAUnTerritoireMaisPasLeDirecteur(): void
    {
        $policy = new TerritoryPolicy($this->checkerFor(['u-admin' => 'pub_functional_admin', 'u-director' => 'pub_school_director', 'u-inspector' => 'pub_district_inspector']));

        $policy->authorizeAssign(new Principal('u-admin', 't1'));
        $policy->authorizeManage(new Principal('u-admin', 't1'), 'e1');
        foreach (['u-director', 'u-inspector'] as $user) {
            try {
                $policy->authorizeAssign(new Principal($user, 't1'));
                self::fail($user . ' ne doit pas pouvoir rattacher des établissements à un territoire.');
            } catch (HttpException $e) {
                self::assertSame(403, $e->status);
            }
        }
    }

    /** @param array<string, string> $roleByUser */
    private function checkerFor(array $roleByUser): PermissionChecker
    {
        $grants = self::$grants;

        return new class ($roleByUser, $grants) implements PermissionChecker {
            /**
             * @param array<string, string> $roleByUser
             * @param array<string, list<string>> $grants
             */
            public function __construct(private readonly array $roleByUser, private readonly array $grants)
            {
            }

            public function can(Principal $principal, string $permission, ?string $establishmentId = null): bool
            {
                return in_array($permission, $this->grants[$this->roleByUser[$principal->userId] ?? ''] ?? [], true);
            }
        };
    }
}
