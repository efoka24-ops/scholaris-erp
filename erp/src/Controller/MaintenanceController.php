<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Database\Migrator;
use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Throwable;

/**
 * Maintenance du schema, depuis l'application.
 *
 * L'hebergement mutualise n'offre pas de shell utilisable : appliquer une
 * migration supposait jusqu'ici d'ouvrir une console web et de taper une
 * commande, ce qui se remet a plus tard et laisse tourner un schema en retard
 * sur le code — le pire etat possible.
 *
 * Cet ecran ne fait qu'une chose : jouer les fichiers de migration livres avec
 * l'application, ceux-la et aucun autre. Il n'execute ni SQL saisi, ni code
 * transmis ; il n'y a rien a y injecter. Il est reserve au Super Admin,
 * protege comme toute ecriture par le jeton CSRF, et chaque execution est
 * journalisee.
 */
final class MaintenanceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->assertSuperAdmin();

        $migrator = $this->migrator();
        $applied = [];

        if ($migrator->tableExists('migrations')) {
            $applied = array_column(
                $this->app->tenant()->global(fn () => $this->app->db()->select(
                    'SELECT filename, applied_at FROM migrations ORDER BY filename'
                )),
                'applied_at',
                'filename'
            );
        }

        $files = glob($this->app->basePath().'/database/migrations/*.sql');
        sort($files);

        $migrations = [];

        foreach ($files === false ? [] : $files as $file) {
            $name = basename($file);

            $migrations[] = [
                'name' => $name,
                'applied_at' => $applied[$name] ?? null,
            ];
        }

        return $this->view('platform.maintenance', [
            'migrations' => $migrations,
            'pending' => count(array_filter($migrations, static fn (array $m): bool => $m['applied_at'] === null)),
            'driver' => $this->app->db()->driver(),
            'phpVersion' => PHP_VERSION,
        ]);
    }

    public function migrate(Request $request): Response
    {
        $this->assertSuperAdmin();

        try {
            $ran = $this->app->tenant()->global(fn (): array => $this->migrator()->migrate());
        } catch (Throwable $e) {
            // Le message d'erreur du moteur est la seule information utile pour
            // corriger une migration qui echoue : le masquer obligerait a
            // deviner.
            return $this->redirectWithError(
                '/admin/maintenance',
                'La migration a echoue : '.$e->getMessage()
            );
        }

        $this->audit('schema.migrate', implode(', ', $ran), $request->ip());

        if ($ran === []) {
            return $this->redirectWithSuccess('/admin/maintenance', 'Le schema etait deja a jour.');
        }

        return $this->redirectWithSuccess(
            '/admin/maintenance',
            count($ran).' migration(s) appliquee(s) : '.implode(', ', $ran)
        );
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->app->db(), $this->app->basePath().'/database/migrations');
    }

    private function assertSuperAdmin(): void
    {
        if (! $this->app->rbac()->isSuperAdmin()) {
            throw new HttpException(403, 'Reserve au Super Admin.');
        }
    }

    private function audit(string $action, string $detail, ?string $ip): void
    {
        $this->app->tenant()->global(function () use ($action, $detail, $ip): void {
            $this->app->db()->execute(
                'INSERT INTO audit_logs (id, user_id, action, resource, resource_id, ip_address, timestamp)
                 VALUES (:id, :user, :action, :resource, :resource_id, :ip, :timestamp)',
                [
                    'id' => Table::uuid(),
                    'user' => $this->app->auth()->id(),
                    'action' => $action,
                    'resource' => 'migrations',
                    'resource_id' => substr($detail, 0, 255),
                    'ip' => $ip,
                    'timestamp' => date('Y-m-d H:i:s'),
                ]
            );
        });
    }
}
