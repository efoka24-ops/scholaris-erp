<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Table;

/**
 * Parcours de toutes les pages de l'application.
 *
 * Chaque module ajoute des ecrans, et une erreur de gabarit (variable absente,
 * colonne mal nommee) ne se voit qu'a l'affichage. Ce test ouvre chaque page
 * avec les droits necessaires et verifie qu'aucune ne tombe en erreur serveur.
 *
 * Il ne remplace pas les tests metier : il attrape la classe de defauts que
 * ceux-ci ne voient pas, parce qu'ils n'affichent rien.
 */
final class RoutesSmokeTest extends TestCase
{
    /** Pages de l'espace authentifie, ouvertes avec tous les droits. */
    private const AUTHENTICATED_PAGES = [
        '/dashboard',
        '/students',
        '/students/create',
        '/classrooms',
        '/classrooms/create',
        '/enrollments',
        '/enrollments/create',
        '/timetable',
        '/attendance',
        '/grades',
        '/bulletins',
        '/discipline',
        '/exams',
        '/finance',
        '/finance/invoices',
        '/finance/fee-structures',
        '/health',
        '/library',
        '/communication',
        '/messages',
        '/annees-scolaires',
        '/parametres',
    ];

    /**
     * Pages dependant d'une fonctionnalite optionnelle.
     *
     * Pour un college, ces modules existent mais sont masques par defaut :
     * ils repondent 404 tant que l'Admin ne les a pas actives.
     *
     * @var array<string, string>
     */
    private const OPTIONAL_PAGES = [
        '/transport' => 'life.transport',
        '/catering' => 'life.catering',
        '/patrimoine' => 'life.assets',
        '/hr' => 'hr.payroll',
    ];

    /** Pages accessibles sans compte. */
    private const PUBLIC_PAGES = [
        '/',
        '/login',
        '/pre-inscription',
        '/demande-etablissement',
        '/demande-etablissement/suivi',
        '/bulletins/verification',
        '/hors-ligne',
        '/up',
    ];

    public function testToutesLesPagesPubliquesRepondent(): void
    {
        foreach (self::PUBLIC_PAGES as $path) {
            $response = $this->request('GET', $path);

            $this->assertSame(200, $response->status(), "La page publique {$path} doit repondre 200");
        }
    }

    public function testToutesLesPagesAuthentifieesRepondent(): void
    {
        $userId = $this->createUser($this->tenantA, 'complet@a.cm');
        $this->giveRole($userId, 'TOUS_DROITS', $this->everyPermission());
        $this->prepareTenantData();
        $this->actingAs($userId);

        foreach (self::AUTHENTICATED_PAGES as $path) {
            $response = $this->request('GET', $path);

            // 200 attendu ; une redirection resterait acceptable (page qui
            // renvoie vers une autre), mais jamais une erreur serveur.
            $this->assertTrue(
                $response->status() < 500,
                "La page {$path} tombe en erreur serveur (".$response->status().')'
            );

            $this->assertSame(200, $response->status(), "La page {$path} doit repondre 200");
        }
    }

    public function testLesPagesSensiblesRestentFermeesSansDroit(): void
    {
        // Un compte sans aucune permission ne doit ouvrir aucun module : la
        // navigation les masque, mais l'URL directe doit aussi etre refusee.
        $userId = $this->createUser($this->tenantA, 'sansdroit@a.cm');
        $this->giveRole($userId, 'AUCUN', []);
        $this->actingAs($userId);

        foreach (['/students', '/finance', '/health', '/exams', '/library'] as $path) {
            $response = $this->request('GET', $path);

            $this->assertSame(403, $response->status(), "La page {$path} doit etre refusee sans droit");
        }
    }

    public function testUnModuleOptionnelEstIntrouvableTantQuIlNEstPasActive(): void
    {
        $userId = $this->createUser($this->tenantA, 'complet@a.cm');
        $this->giveRole($userId, 'TOUS_DROITS', $this->everyPermission());
        $this->prepareTenantData();
        $this->actingAs($userId);

        // Masque par defaut : la reponse est 404 et non 403. Le module
        // n'existe pas encore ici, il ne s'agit pas d'un refus de droit —
        // c'est ce qui evite de reveler des fonctions etrangeres a l'ecole.
        foreach (self::OPTIONAL_PAGES as $path => $feature) {
            $response = $this->request('GET', $path);

            $this->assertSame(404, $response->status(), "La page {$path} doit etre introuvable tant que {$feature} est inactive");
        }
    }

    public function testUnModuleOptionnelSOuvreUneFoisActive(): void
    {
        $userId = $this->createUser($this->tenantA, 'complet@a.cm');
        $this->giveRole($userId, 'TOUS_DROITS', $this->everyPermission());
        $this->prepareTenantData();

        $this->enableFeatures(array_values(self::OPTIONAL_PAGES));
        $this->actingAs($userId);

        foreach (array_keys(self::OPTIONAL_PAGES) as $path) {
            $response = $this->request('GET', $path);

            $this->assertSame(200, $response->status(), "La page {$path} doit s ouvrir une fois la fonctionnalite activee");
        }
    }

    /**
     * Active des fonctionnalites optionnelles sur l'etablissement de test.
     *
     * @param  list<string>  $features
     */
    private function enableFeatures(array $features): void
    {
        $values = [];

        foreach ($features as $feature) {
            $values[$feature] = true;
        }

        $this->db->execute(
            'UPDATE tenants SET config_json = :config WHERE id = :id',
            [
                'config' => json_encode(['features' => $values]),
                'id' => $this->tenantA,
            ]
        );

        $this->app->resetFeatures();
    }

    public function testAucuneRouteNEntreEnCollisionAvecUnFichierPublic(): void
    {
        // Le serveur web sert d'abord ce qui existe dans public/ : une route
        // portant le nom d'un dossier ou d'un fichier ne serait jamais
        // atteinte. C'est arrive avec "/assets", masquee par public/assets.
        $publicDir = $this->basePath().'/public';
        $entries = scandir($publicDir);
        $reserved = [];

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && ! str_starts_with($entry, '.')) {
                $reserved[] = '/'.$entry;
            }
        }

        $collisions = [];

        foreach (array_merge(self::AUTHENTICATED_PAGES, self::PUBLIC_PAGES) as $path) {
            // Seul le premier segment compte : c'est lui que le serveur
            // resout contre le systeme de fichiers.
            $segment = '/'.explode('/', ltrim($path, '/'))[0];

            if ($segment !== '/' && in_array($segment, $reserved, true)) {
                $collisions[] = $path.' masquee par public'.$segment;
            }
        }

        $this->assertSame([], $collisions, 'Collision route / fichier public : '.implode(', ', $collisions));
    }

    /**
     * Donnees minimales pour que les pages aient quelque chose a afficher :
     * une annee academique, un cycle, un niveau, une classe.
     */
    private function prepareTenantData(): void
    {
        $now = date('Y-m-d H:i:s');
        $yearId = Table::uuid();

        $this->db->execute(
            'INSERT INTO academic_years (id, tenant_id, label, start_date, end_date, status, created_at)
             VALUES (:id, :tenant, :label, :start, :end, :status, :created_at)',
            [
                'id' => $yearId,
                'tenant' => $this->tenantA,
                'label' => '2026-2027',
                'start' => '2026-09-01',
                'end' => '2027-06-30',
                'status' => 'ACTIVE',
                'created_at' => $now,
            ]
        );

        $cycleId = Table::uuid();

        $this->db->execute(
            'INSERT INTO cycles (id, tenant_id, code, name, sort_order, created_at)
             VALUES (:id, :tenant, :code, :name, :sort, :created_at)',
            ['id' => $cycleId, 'tenant' => $this->tenantA, 'code' => 'COL', 'name' => 'College', 'sort' => 1, 'created_at' => $now]
        );

        $levelId = Table::uuid();

        $this->db->execute(
            'INSERT INTO levels (id, tenant_id, code, name, sort_order, cycle_id, created_at)
             VALUES (:id, :tenant, :code, :name, :sort, :cycle, :created_at)',
            ['id' => $levelId, 'tenant' => $this->tenantA, 'code' => '6E', 'name' => '6eme', 'sort' => 1, 'cycle' => $cycleId, 'created_at' => $now]
        );

        $this->db->execute(
            'INSERT INTO classrooms (id, tenant_id, code, name, capacity, level_id, section, created_at, updated_at)
             VALUES (:id, :tenant, :code, :name, :capacity, :level, :section, :created_at, :updated_at)',
            [
                'id' => Table::uuid(),
                'tenant' => $this->tenantA,
                'code' => '6EA',
                'name' => '6eme A',
                'capacity' => 40,
                'level' => $levelId,
                'section' => 'FRANCOPHONE',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $this->createStudent($this->tenantA, 'A/001');
    }

    /**
     * Toutes les permissions du referentiel, pour ouvrir chaque page.
     *
     * @return list<string>
     */
    private function everyPermission(): array
    {
        $matrix = require $this->basePath().'/database/rbac-matrix.php';

        return array_map(
            static fn (array $entry): string => $entry[0].':'.$entry[1],
            $matrix['permissions']
        );
    }
}
