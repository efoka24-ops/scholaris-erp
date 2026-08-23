<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Auth\Auth;
use Scholaris\Database\SchoolFactory;
use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;

/**
 * Instruction des demandes de creation d'etablissement, par le Super Admin.
 *
 * L'approbation cree en une transaction l'etablissement, le compte de son
 * responsable et son role : un echec en cours de route ne doit pas laisser un
 * etablissement sans administrateur, ni un compte rattache a rien.
 */
final class EstablishmentRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $this->assertSuperAdmin();

        $status = $request->string('status', 'PENDING');

        if (! in_array($status, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
            $status = 'PENDING';
        }

        return $this->view('admin.establishment-requests', [
            'requests' => $this->app->db()->select(
                'SELECT * FROM establishment_requests WHERE request_status = :status ORDER BY created_at DESC',
                ['status' => $status]
            ),
            'status' => $status,
            'pendingCount' => (int) $this->app->db()->scalar(
                'SELECT COUNT(*) FROM establishment_requests WHERE request_status = :status',
                ['status' => 'PENDING']
            ),
        ]);
    }

    public function approve(Request $request): Response
    {
        $this->assertSuperAdmin();

        $demand = $this->findRequest((string) $request->attribute('id'));

        if ($demand['request_status'] !== 'PENDING') {
            return $this->redirectWithError('/admin/etablissements', 'Cette demande a deja ete traitee.');
        }

        $password = $this->generatePassword();

        $tenantId = $this->app->db()->transaction(function () use ($demand, $password): string {
            $tenantId = Table::uuid();
            $now = date('Y-m-d H:i:s');

            $this->app->db()->execute(
                'INSERT INTO tenants (id, code, name, type, status, address, phone, email, created_at, updated_at)
                 VALUES (:id, :code, :name, :type, :status, :address, :phone, :email, :created_at, :updated_at)',
                [
                    'id' => $tenantId,
                    'code' => $demand['code'],
                    'name' => $demand['name'],
                    'type' => $demand['type'],
                    'status' => $demand['status'],
                    'address' => $demand['address'],
                    'phone' => $demand['phone'],
                    'email' => $demand['email'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $userId = Table::uuid();

            $this->app->db()->execute(
                'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, phone, status, created_at, updated_at)
                 VALUES (:id, :tenant, :email, :hash, :first, :last, :phone, :status, :created_at, :updated_at)',
                [
                    'id' => $userId,
                    'tenant' => $tenantId,
                    'email' => $demand['director_email'],
                    'hash' => Auth::hash($password),
                    'first' => $demand['director_first_name'],
                    'last' => $demand['director_last_name'],
                    'phone' => $demand['director_phone'],
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $roleId = $this->app->db()->scalar(
                'SELECT id FROM roles WHERE tenant_id IS NULL AND name = :name',
                ['name' => 'Admin Établissement']
            );

            if ($roleId !== null) {
                $this->app->db()->execute(
                    'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
                    ['user' => $userId, 'role' => $roleId]
                );
            }

            $this->seedStructure($tenantId, (string) $demand['type'], $now);

            $this->app->db()->execute(
                'UPDATE establishment_requests
                 SET request_status = :status, created_tenant_id = :tenant, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'status' => 'APPROVED',
                    'tenant' => $tenantId,
                    'updated_at' => $now,
                    'id' => $demand['id'],
                ]
            );

            return $tenantId;
        });

        $this->audit('establishment.approve', (string) $demand['id'], $request->ip());

        // Le mot de passe genere n'est affiche qu'ici, une seule fois : il n'est
        // stocke nulle part en clair.
        return $this->view('admin.establishment-approved', [
            'demand' => $demand,
            'password' => $password,
            'tenantId' => $tenantId,
        ]);
    }

    public function reject(Request $request): Response
    {
        $this->assertSuperAdmin();

        $demand = $this->findRequest((string) $request->attribute('id'));

        if ($demand['request_status'] !== 'PENDING') {
            return $this->redirectWithError('/admin/etablissements', 'Cette demande a deja ete traitee.');
        }

        $reason = $request->string('reason');

        if ($reason === '') {
            return $this->redirectWithError('/admin/etablissements', 'Indiquez le motif du refus.');
        }

        $this->app->db()->execute(
            'UPDATE establishment_requests
             SET request_status = :status, rejection_reason = :reason, updated_at = :updated_at
             WHERE id = :id',
            [
                'status' => 'REJECTED',
                'reason' => $reason,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $demand['id'],
            ]
        );

        $this->audit('establishment.reject', (string) $demand['id'], $request->ip());

        return $this->redirectWithSuccess('/admin/etablissements', 'Demande refusee.');
    }

    /**
     * @return array<string, mixed>
     */
    private function findRequest(string $id): array
    {
        $demand = $this->app->db()->selectOne(
            'SELECT * FROM establishment_requests WHERE id = :id',
            ['id' => $id]
        );

        if ($demand === null) {
            throw new HttpException(404);
        }

        return $demand;
    }

    /**
     * Le Super Admin est le seul habilite : la table des demandes n'est pas
     * rattachee a un etablissement, le controle par permission ne suffirait pas.
     */
    private function assertSuperAdmin(): void
    {
        if (! $this->app->rbac()->isSuperAdmin()) {
            throw new HttpException(403, 'Reserve au Super Admin.');
        }
    }

    /**
     * Pose les cycles et niveaux correspondant au type demande.
     *
     * Une ecole primaire recoit la SIL au CM2, un lycee les deux cycles du
     * secondaire. Appliquer partout la meme structure obligerait chaque
     * nouvel etablissement a supprimer des niveaux etrangers au sien — et un
     * directeur d'ecole primaire ouvrirait sa plateforme sur une classe de
     * terminale.
     */
    private function seedStructure(string $tenantId, string $type, string $now): void
    {
        foreach (SchoolFactory::structureFor($type) as $cycleOrder => [$code, $name, $levels]) {
            $cycleId = Table::uuid();

            $this->app->db()->execute(
                'INSERT INTO cycles (id, tenant_id, code, name, sort_order, created_at)
                 VALUES (:id, :tenant, :code, :name, :sort, :created_at)',
                [
                    'id' => $cycleId,
                    'tenant' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'sort' => $cycleOrder + 1,
                    'created_at' => $now,
                ]
            );

            foreach ($levels as $levelOrder => [$levelCode, $levelName]) {
                $this->app->db()->execute(
                    'INSERT INTO levels (id, tenant_id, code, name, sort_order, cycle_id, created_at)
                     VALUES (:id, :tenant, :code, :name, :sort, :cycle, :created_at)',
                    [
                        'id' => Table::uuid(),
                        'tenant' => $tenantId,
                        'code' => $levelCode,
                        'name' => $levelName,
                        'sort' => $levelOrder + 1,
                        'cycle' => $cycleId,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    /**
     * Mot de passe initial : lisible au telephone, mais assez long pour ne pas
     * etre devine. Il doit etre change a la premiere connexion.
     */
    private function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $password = '';

        for ($i = 0; $i < 12; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    private function audit(string $action, string $resourceId, ?string $ip): void
    {
        $this->app->db()->execute(
            'INSERT INTO audit_logs (id, user_id, action, resource, resource_id, ip_address, timestamp)
             VALUES (:id, :user, :action, :resource, :resource_id, :ip, :timestamp)',
            [
                'id' => Table::uuid(),
                'user' => $this->app->auth()->id(),
                'action' => $action,
                'resource' => 'establishment_requests',
                'resource_id' => $resourceId,
                'ip' => $ip,
                'timestamp' => date('Y-m-d H:i:s'),
            ]
        );
    }
}
