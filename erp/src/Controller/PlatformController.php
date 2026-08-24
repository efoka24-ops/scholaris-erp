<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Platform\PlatformStats;

/**
 * Espace de l'administrateur de la plateforme.
 *
 * A ne pas confondre avec l'administration d'une ecole : le Super Admin ne
 * gere pas les eleves ni les notes d'un etablissement, il gere le parc
 * d'etablissements. Il n'appartient d'ailleurs a aucun d'eux (tenant_id nul),
 * donc aucune donnee scolaire ne lui est accessible par defaut.
 *
 * Pour consulter une ecole, il s'y place explicitement : c'est une action
 * deliberee, tracee, et non un acces permanent.
 */
final class PlatformController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $this->assertSuperAdmin();

        $stats = new PlatformStats($this->app->db());

        // Toutes ces lectures traversent les etablissements : elles doivent
        // donc etre explicitement hors scope.
        $data = $this->app->tenant()->global(fn (): array => [
            'stats' => $stats->overview(),
            'map' => $stats->byRegion(),
            'tenants' => $this->app->db()->select(
                'SELECT t.*,
                        (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS users_count,
                        (SELECT COUNT(*) FROM students s WHERE s.tenant_id = t.id AND s.deleted_at IS NULL) AS students_count
                 FROM tenants t
                 WHERE t.deleted_at IS NULL
                 ORDER BY t.name'
            ),
            'pendingRequests' => (int) $this->app->db()->scalar(
                'SELECT COUNT(*) FROM establishment_requests WHERE request_status = :status',
                ['status' => 'PENDING']
            ),
            'totalStudents' => (int) $this->app->db()->scalar(
                'SELECT COUNT(*) FROM students WHERE deleted_at IS NULL'
            ),
            'totalUsers' => (int) $this->app->db()->scalar(
                'SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'
            ),
            'recentLogins' => $this->app->db()->select(
                'SELECT a.timestamp, a.ip_address, u.email, u.first_name, u.last_name, t.name AS tenant_name
                 FROM audit_logs a
                 LEFT JOIN users u ON u.id = a.user_id
                 LEFT JOIN tenants t ON t.id = u.tenant_id
                 WHERE a.action = :action
                 ORDER BY a.timestamp DESC LIMIT 10',
                ['action' => 'login']
            ),
        ]);

        return $this->view('platform.dashboard', $data);
    }

    /**
     * Se place dans un etablissement pour le consulter.
     *
     * L'action est journalisee : un administrateur de plateforme qui entre
     * dans les donnees d'une ecole doit laisser une trace, c'est ce qui
     * distingue un acces legitime d'un acces silencieux.
     */
    public function enter(Request $request): Response
    {
        $this->assertSuperAdmin();

        $tenantId = (string) $request->attribute('id');

        $tenant = $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
            'SELECT * FROM tenants WHERE id = :id AND deleted_at IS NULL',
            ['id' => $tenantId]
        ));

        if ($tenant === null) {
            throw new HttpException(404);
        }

        $this->app->session()->set('impersonated_tenant_id', $tenantId);

        // Pose aussi le contexte immediatement : la suite de cette requete
        // (journalisation, redirection) doit deja voir l'etablissement, sans
        // attendre le prochain appel.
        $this->app->tenant()->set($tenantId);

        $this->app->db()->execute(
            'INSERT INTO audit_logs (id, user_id, action, resource, resource_id, ip_address, timestamp)
             VALUES (:id, :user, :action, :resource, :resource_id, :ip, :timestamp)',
            [
                'id' => \Scholaris\Database\Table::uuid(),
                'user' => $this->currentUserId(),
                'action' => 'platform.enter_tenant',
                'resource' => 'tenants',
                'resource_id' => $tenantId,
                'ip' => $request->ip(),
                'timestamp' => date('Y-m-d H:i:s'),
            ]
        );

        return $this->redirectWithSuccess('/dashboard', 'Vous consultez '.$tenant['name'].'.');
    }

    public function leave(Request $request): Response
    {
        $this->assertSuperAdmin();

        $this->app->session()->forget('impersonated_tenant_id');
        $this->app->tenant()->clear();

        return $this->redirectWithSuccess('/admin', 'Retour a l administration de la plateforme.');
    }

    private function assertSuperAdmin(): void
    {
        if (! $this->app->rbac()->isSuperAdmin()) {
            throw new HttpException(403, 'Reserve a l administrateur de la plateforme.');
        }
    }
}
