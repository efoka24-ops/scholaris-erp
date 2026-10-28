<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Database\Migrator;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Apps\Secondary\Policies\RoleMatrix;

/** Les permissions utilisées par les modules sont accordées aux bons rôles (et refusées aux autres). */
final class RolePermissionsTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $grants = [];

    /** @var array<string, array{level: string, sensitive: bool}> */
    private array $roles = [];

    private \PDO $pdo;

    protected function setUp(): void
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        $kernel->container()->get(Migrator::class)->migrate();
        $pdo = $kernel->container()->get(ConnectionFactory::class)->pdo();
        $this->pdo = $pdo;
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

    /**
     * Matrice des droits du CDC secondaire (cdc-secondaire.md, « Matrice des droits »), recopiée telle quelle : module => rôle => cellule.
     * L = lecture, É = écriture, V = validation, — = aucun accès. Les précisions entre parenthèses du CDC sont dans le code de cellule.
     */
    private const SECONDARY_CDC_MATRIX = [
        'parametrage' => ['R03' => 'V', 'R04' => 'É', 'R05' => '—', 'R08' => '—', 'R09' => '—', 'R11' => 'L', 'R17' => '—', 'R19' => '—'],
        'inscriptions' => ['R03' => 'V', 'R04' => 'L', 'R05' => 'L', 'R08' => 'L', 'R09' => '—', 'R11' => 'L', 'R17' => 'L', 'R19' => 'L'],
        'emplois_du_temps' => ['R03' => 'V', 'R04' => 'É', 'R05' => 'L', 'R08' => 'L', 'R09' => 'L', 'R11' => '—', 'R17' => 'L', 'R19' => 'L'],
        'cahier_de_textes' => ['R03' => 'L', 'R04' => 'V', 'R05' => '—', 'R08' => 'L', 'R09' => 'É', 'R11' => '—', 'R17' => 'L', 'R19' => '—'],
        'presences_discipline' => ['R03' => 'V', 'R04' => 'L', 'R05' => 'É', 'R08' => 'L', 'R09' => 'É appel', 'R11' => '—', 'R17' => 'L', 'R19' => 'L'],
        'notes' => ['R03' => 'L', 'R04' => 'V', 'R05' => '—', 'R08' => 'L', 'R09' => 'É', 'R11' => '—', 'R17' => 'L', 'R19' => 'L'],
        'bulletins_conseil' => ['R03' => 'V', 'R04' => 'É', 'R05' => 'É conduite', 'R08' => 'É appréciation générale', 'R09' => 'É appréciation', 'R11' => '—', 'R17' => 'L', 'R19' => 'L'],
        'examens_officiels' => ['R03' => 'V', 'R04' => 'É', 'R05' => '—', 'R08' => 'L', 'R09' => '—', 'R11' => 'L', 'R17' => 'L', 'R19' => 'L'],
        'frais_paiements' => ['R03' => 'L', 'R04' => '—', 'R05' => '—', 'R08' => '—', 'R09' => '—', 'R11' => 'É', 'R17' => 'L', 'R19' => 'L, paiement'],
        'ateliers' => ['R03' => 'L', 'R04' => 'L', 'R05' => '—', 'R08' => '—', 'R09' => 'É séances', 'R11' => 'L coûts', 'R17' => 'L', 'R19' => '—'],
        'tableaux_de_bord' => ['R03' => 'L', 'R04' => 'L', 'R05' => 'L', 'R08' => 'L', 'R09' => 'L', 'R11' => 'L finances', 'R17' => '—', 'R19' => '—'],
    ];

    /**
     * Traduction d'une cellule en permissions `secondary.*`. É et V donnent aussi la lecture du module. L'élève (R17) et le parent
     * (R19) lisent tout par leur propre périmètre (`self.read`, `family.read`), jamais par les droits de lecture du personnel.
     */
    private const SECONDARY_CELL_PERMISSIONS = [
        'parametrage' => ['L' => ['settings.read'], 'É' => ['settings.read', 'settings.write'], 'V' => ['settings.read', 'settings.manage']],
        'inscriptions' => ['L' => ['enrol.read'], 'V' => ['enrol.read', 'enrol.validate']],
        'emplois_du_temps' => ['L' => ['timetable.read'], 'É' => ['timetable.read', 'timetable.manage'], 'V' => ['timetable.read', 'timetable.validate']],
        'cahier_de_textes' => ['L' => ['syllabus.read'], 'É' => ['syllabus.read', 'lesson.log'], 'V' => ['syllabus.read', 'syllabus.visa']],
        'presences_discipline' => ['L' => ['attendance.read'], 'É' => ['attendance.read', 'attendance.manage', 'discipline.manage'], 'É appel' => ['attendance.read', 'attendance.take'], 'V' => ['attendance.read', 'discipline.validate']],
        'notes' => ['L' => ['grades.read'], 'É' => ['grades.read', 'grades.enter'], 'V' => ['grades.read', 'grades.validate']],
        'bulletins_conseil' => ['É' => ['reportcard.read', 'council.prepare'], 'É conduite' => ['reportcard.read', 'conduct.enter'], 'É appréciation générale' => ['reportcard.read', 'appraisal.general'],
            'É appréciation' => ['reportcard.read', 'appraisal.subject'], 'V' => ['reportcard.read', 'reportcard.validate', 'council.preside']],
        'examens_officiels' => ['L' => ['exams.read'], 'É' => ['exams.read', 'exams.manage'], 'V' => ['exams.read', 'exams.validate']],
        'frais_paiements' => ['L' => ['fees.read'], 'É' => ['fees.read', 'billing.manage']],
        'ateliers' => ['L' => ['workshops.read'], 'É séances' => ['workshops.read', 'workshops.session'], 'L coûts' => ['workshops.costs.read']],
        'tableaux_de_bord' => ['L' => ['dashboard.read'], 'L finances' => ['finance.read']],
    ];

    /** Codes de rôle des colonnes de la matrice ; R09 = rôle commun « enseignant » (décision du propriétaire). */
    private const SECONDARY_MATRIX_ROLES = [
        'R03' => 'sec_chef_etablissement', 'R04' => 'sec_censeur', 'R05' => 'sec_surveillant_general', 'R08' => 'sec_professeur_principal',
        'R09' => 'enseignant', 'R11' => 'sec_intendant', 'R17' => 'sec_eleve', 'R19' => 'sec_parent',
    ];

    /**
     * Droits hors matrice du CDC : spec 005 §2 (missions du rôle) et lecture du profil et des listes de référence (squelette).
     * Préfixe `secondary.` sauf mention `@`.
     */
    private const SECONDARY_ROLE_EXTRAS = [
        'sec_promoteur' => ['dashboard.read', 'finance.read', 'fees.validate', 'profile.read', 'catalogue.read'],
        'sec_reseau' => ['network.read'],
        'sec_chef_etablissement' => ['discipline.council.preside', 'document.sign', 'profile.read', 'catalogue.read'],
        'sec_censeur' => ['grades.lock', 'profile.read', 'catalogue.read'],
        'sec_surveillant_general' => ['profile.read'],
        'sec_chef_travaux' => ['workshops.manage', 'stock.manage', 'safety.manage', 'internships.manage'],
        'sec_animateur_pedagogique' => ['syllabus.manage', 'commontests.manage', 'catalogue.read'],
        'sec_professeur_principal' => ['class.synthesis'],
        'enseignant' => [],
        'sec_conseiller_orientation' => ['orientation.manage', 'catalogue.read'],
        'sec_intendant' => ['fees.catalog', 'profile.read', 'catalogue.read'],
        'sec_caissier' => ['billing.cash'],
        'sec_scolarite' => ['enrol.manage', 'documents.issue', 'exams.lists', 'profile.read', 'catalogue.read'],
        'sec_infirmier' => ['infirmary.manage'],
        'sec_responsable_services' => ['services.manage'],
        'sec_bibliothecaire' => ['library.manage'],
        'sec_eleve' => ['self.read'],
        'sec_delegue_classe' => ['attendance.countersign'],
        'sec_parent' => ['family.read', 'family.pay'],
        'sec_bureau_apee' => ['apee.read'],
        'sec_inspecteur' => ['inspection.read'],
        'sec_delegation' => ['territory.read'],
        'sec_brigade_controle' => ['control.read'],
        'sec_admin_fonctionnel' => ['settings.manage', 'profile.manage', 'documents.template.manage', 'profile.read', 'catalogue.read', '@identity.manage', '@identity.roles.assign'],
    ];

    /** @return list<string> permissions attendues d'un rôle du secondaire (matrice du CDC + droits hors matrice) */
    private static function secondaryExpected(string $role): array
    {
        $permissions = [];
        $column = array_search($role, self::SECONDARY_MATRIX_ROLES, true);
        if (is_string($column)) {
            foreach (self::SECONDARY_CDC_MATRIX as $module => $cells) {
                $cell = $cells[$column];
                if ($cell === '—' || in_array($column, ['R17', 'R19'], true)) {
                    continue;
                }
                self::assertArrayHasKey($cell, self::SECONDARY_CELL_PERMISSIONS[$module], $module . ' ' . $column);
                array_push($permissions, ...self::SECONDARY_CELL_PERMISSIONS[$module][$cell]);
            }
        }
        array_push($permissions, ...self::SECONDARY_ROLE_EXTRAS[$role]);
        $permissions = array_map(static fn (string $p): string => str_starts_with($p, '@') ? substr($p, 1) : 'secondary.' . $p, $permissions);
        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }

    /** @return array<string, array{0: string}> */
    public static function secondaryRoles(): array
    {
        $roles = [];
        foreach (array_keys(self::SECONDARY_ROLE_EXTRAS) as $role) {
            $roles[$role] = [$role];
        }

        return $roles;
    }

    /** T808 : chaque rôle du secondaire a exactement les permissions de la matrice du CDC (`secondary.*` seulement pour « enseignant »). */
    /** @dataProvider secondaryRoles */
    public function testRoleSecondaireAvecLesPermissionsExactesDeLaMatrice(string $role): void
    {
        $granted = $this->grants[$role] ?? [];
        if ($role === 'enseignant') {
            $granted = array_values(array_filter($granted, static fn (string $p): bool => str_starts_with($p, 'secondary.')));
        }
        sort($granted);

        self::assertSame(self::secondaryExpected($role), $granted, $role);
    }

    public function testRolesDuSecondaireSemesUneFoisSansRoleEnseignantPropre(): void
    {
        $codes = array_values(array_filter(array_keys($this->roles), static fn (string $code): bool => str_starts_with($code, 'sec_')));
        sort($codes);
        $expected = array_values(array_filter(array_keys(self::SECONDARY_ROLE_EXTRAS), static fn (string $code): bool => $code !== 'enseignant'));
        sort($expected);

        self::assertSame($expected, $codes);
        self::assertCount(23, $codes, 'R01 à R24, R09 étant le rôle commun « enseignant ».');
        self::assertArrayNotHasKey('sec_enseignant', $this->roles);
    }

    public function testNiveauSensibiliteAgregatsEtDureeDesRolesDuSecondaire(): void
    {
        $sensitive = array_values(array_filter(array_keys($this->roles), fn (string $code): bool => str_starts_with($code, 'sec_') && $this->roles[$code]['sensitive']));
        sort($sensitive);
        self::assertSame(['sec_admin_fonctionnel', 'sec_caissier', 'sec_censeur', 'sec_chef_etablissement', 'sec_infirmier', 'sec_intendant'], $sensitive);
        self::assertSame('tenant', $this->roles['sec_reseau']['level']);
        self::assertSame('tenant', $this->roles['sec_admin_fonctionnel']['level']);
        self::assertSame('territory', $this->roles['sec_delegation']['level']);

        $pdo = $this->pdo;
        $limited = [];
        foreach ($pdo->query('SELECT code, max_assignment_days FROM roles WHERE max_assignment_days IS NOT NULL ORDER BY code')->fetchAll() as $row) {
            $limited[(string) $row['code']] = (int) $row['max_assignment_days'];
        }
        self::assertSame(['sec_brigade_controle' => 30, 'sec_delegation' => 30, 'sec_inspecteur' => 30], $limited, 'Comptes externes R21 à R23 seulement.');
        $aggregate = $pdo->query("SELECT code FROM roles WHERE aggregate_only = 1 AND code LIKE 'sec_%'")->fetchAll();
        self::assertSame(['sec_reseau'], array_column($aggregate, 'code'));
    }

    public function testSeparationDesTachesDuSecondaire(): void
    {
        // Qui saisit ne verrouille pas ; qui encaisse ne gère pas la facturation ; seul l'infirmier tient l'infirmerie.
        foreach (['enseignant', 'sec_professeur_principal'] as $role) {
            self::assertNotContains('secondary.grades.lock', $this->grants[$role] ?? [], $role);
            self::assertNotContains('secondary.grades.validate', $this->grants[$role] ?? [], $role);
        }
        self::assertNotContains('secondary.billing.manage', $this->grants['sec_caissier'] ?? []);
        $holders = array_keys(array_filter($this->grants, static fn (array $p): bool => in_array('secondary.infirmary.manage', $p, true)));
        self::assertSame(['sec_infirmier'], array_values($holders));
        foreach (['sec_inspecteur', 'sec_delegation', 'sec_brigade_controle', 'sec_reseau'] as $external) {
            foreach ($this->grants[$external] ?? [] as $permission) {
                self::assertMatchesRegularExpression('/\.read$/', $permission, $external . ' : lecture seule');
            }
        }
    }

    public function testLaMatriceDuSqueletteSecondaireEstCouverte(): void
    {
        // Policies/RoleMatrix (squelette, W0S-3) : chacune de ses permissions est accordée au rôle correspondant.
        foreach (RoleMatrix::ROLES as $number => $row) {
            $role = $number === 'R09' ? 'enseignant' : $row['code'];
            foreach (RoleMatrix::permissionsOf($row['code']) as $permission) {
                self::assertContains($permission, $this->grants[$role] ?? [], $number . ' ' . $role);
            }
        }
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
