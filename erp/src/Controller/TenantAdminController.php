<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use RuntimeException;
use Scholaris\Database\SchoolFactory;
use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Support\Cameroon;
use Scholaris\Tenant\TenantContext;

/**
 * Gestion du parc d'etablissements par le Super Admin.
 *
 * Jusqu'ici un etablissement ne pouvait naitre que d'une demande publique : ni
 * creation directe, ni correction d'une coquille dans un nom, ni suspension
 * d'une ecole qui ne paie plus. Il fallait passer par la base.
 *
 * Deux precautions gouvernent ce controleur.
 *
 * D'abord, toutes ces lectures et ecritures traversent les etablissements :
 * elles se font donc explicitement hors scope, faute de quoi le filtrage par
 * etablissement les viderait.
 *
 * Ensuite, un etablissement ne se supprime pas vraiment. Ses eleves, ses notes
 * et ses paiements restent des archives scolaires : la suppression est logique
 * et refusee tant que l'ecole a des eleves, car un dossier scolaire ne
 * s'efface pas sur un clic.
 */
final class TenantAdminController extends Controller
{
    private const TYPES = [
        'PRIMAIRE' => 'Ecole primaire (SIL a CM2)',
        'COLLEGE' => 'College (6eme a 3eme)',
        'LYCEE_GENERAL' => 'Lycee d enseignement general',
        'LYCEE_TECHNIQUE' => 'Lycee d enseignement technique',
        'CENTRE_FORMATION' => 'Centre de formation professionnelle',
        'SUPERIEUR' => 'Enseignement superieur',
    ];

    public function index(Request $request): Response
    {
        $this->assertSuperAdmin();

        $search = trim($request->string('q'));
        $status = $request->string('statut');

        $sql = 'SELECT t.*,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS users_count,
                    (SELECT COUNT(*) FROM students s WHERE s.tenant_id = t.id AND s.deleted_at IS NULL) AS students_count
                FROM tenants t
                WHERE t.deleted_at IS NULL';
        $params = [];

        if ($search !== '') {
            $sql .= ' AND (LOWER(t.name) LIKE :search OR LOWER(t.code) LIKE :search2)';
            // Deux marqueurs distincts : MySQL refuse qu'un meme nom soit
            // reutilise dans une requete preparee.
            $params['search'] = '%'.strtolower($search).'%';
            $params['search2'] = '%'.strtolower($search).'%';
        }

        if ($status === 'ACTIVE' || $status === 'SUSPENDED') {
            $sql .= ' AND t.platform_status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY t.name';

        $tenants = $this->app->tenant()->global(fn () => $this->app->db()->select($sql, $params));

        return $this->view('platform.tenants-index', [
            'tenants' => $tenants,
            'search' => $search,
            'status' => $status,
            'types' => self::TYPES,
            'regions' => Cameroon::regionChoices(),
        ]);
    }

    public function createForm(Request $request): Response
    {
        $this->assertSuperAdmin();

        return $this->view('platform.tenant-create', [
            'types' => self::TYPES,
            'regions' => Cameroon::regionChoices(),
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    /**
     * Creation directe d'un etablissement, avec son responsable.
     *
     * Le meme chemin que l'approbation d'une demande : etablissement, compte du
     * chef d'etablissement, structure pedagogique du type et annee scolaire
     * ouverte. Livrer une coquille vide obligerait le Super Admin a tout monter
     * a la main derriere.
     */
    public function store(Request $request): Response
    {
        $this->assertSuperAdmin();

        $name = trim($request->string('name'));
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $request->string('code')) ?? '');
        $type = $request->string('type');
        $email = trim($request->string('director_email'));
        $firstName = trim($request->string('director_first_name'));
        $lastName = trim($request->string('director_last_name'));

        $errors = [];

        if ($name === '') {
            $errors[] = 'le nom de l etablissement';
        }

        if ($code === '') {
            $errors[] = 'un code compose de lettres ou de chiffres';
        }

        if (! isset(self::TYPES[$type])) {
            $errors[] = 'le type d etablissement';
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'une adresse email valide pour le responsable';
        }

        if ($firstName === '' || $lastName === '') {
            $errors[] = 'le nom et le prenom du responsable';
        }

        if ($errors !== []) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/admin/parc/creer', 'Renseignez '.implode(', ', $errors).'.');
        }

        $region = $request->string('region');
        $region = Cameroon::isRegion($region) ? $region : null;

        try {
            $result = $this->app->tenant()->global(function () use ($code, $name, $type, $email, $firstName, $lastName) {
                $factory = new SchoolFactory($this->app->db(), new TenantContext());

                return $factory->create($code, $name, $type, $email, $firstName, $lastName);
            });
        } catch (\RuntimeException $e) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/admin/parc/creer', $e->getMessage());
        }

        $this->app->tenant()->global(function () use ($result, $region, $request): void {
            $this->app->db()->execute(
                'UPDATE tenants SET region = :region, city = :city, address = :address,
                        phone = :phone, email = :email, status = :status, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'region' => $region,
                    'city' => $request->string('city') ?: null,
                    'address' => $request->string('address') ?: null,
                    'phone' => $request->string('phone') ?: null,
                    'email' => $request->string('email') ?: null,
                    'status' => $request->string('status') === 'PUBLIC' ? 'PUBLIC' : 'PRIVE',
                    'updated_at' => date('Y-m-d H:i:s'),
                    'id' => $result['tenant_id'],
                ]
            );
        });

        $this->audit('tenant.create', $result['tenant_id'], $request->ip());

        $this->app->establishmentMails()->approved(
            [
                'id' => $result['tenant_id'],
                'name' => $name,
                'director_first_name' => $firstName,
                'director_last_name' => $lastName,
                'director_email' => $email,
            ],
            $result['password'],
            $result['tenant_id']
        );

        return $this->view('platform.tenant-created', [
            'name' => $name,
            'code' => $code,
            'email' => $email,
            'password' => $result['password'],
            'levels' => $result['levels'],
        ]);
    }

    public function editForm(Request $request): Response
    {
        $this->assertSuperAdmin();

        $tenant = $this->findTenant((string) $request->attribute('id'));

        return $this->view('platform.tenant-edit', [
            'tenant' => $tenant,
            'types' => self::TYPES,
            'regions' => Cameroon::regionChoices(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->assertSuperAdmin();

        $tenant = $this->findTenant((string) $request->attribute('id'));
        $name = trim($request->string('name'));

        if ($name === '') {
            return $this->redirectWithError(
                '/admin/parc/'.$tenant['id'].'/modifier',
                'Le nom de l etablissement est obligatoire.'
            );
        }

        $type = $request->string('type');

        if (! isset(self::TYPES[$type])) {
            $type = (string) $tenant['type'];
        }

        // Changer de type change les modules ouverts et le vocabulaire, mais
        // jamais la structure deja posee : supprimer des niveaux ou des classes
        // qui portent des eleves ne se decide pas depuis un formulaire.
        $region = $request->string('region');

        $this->app->tenant()->global(function () use ($request, $tenant, $name, $type, $region): void {
            $this->app->db()->execute(
                'UPDATE tenants SET name = :name, type = :type, status = :status,
                        address = :address, phone = :phone, email = :email,
                        region = :region, city = :city,
                        public_enrollment_enabled = :enrollment, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'name' => $name,
                    'type' => $type,
                    'status' => $request->string('status') === 'PUBLIC' ? 'PUBLIC' : 'PRIVE',
                    'address' => $request->string('address') ?: null,
                    'phone' => $request->string('phone') ?: null,
                    'email' => $request->string('email') ?: null,
                    'region' => Cameroon::isRegion($region) ? $region : null,
                    'city' => $request->string('city') ?: null,
                    'enrollment' => $request->input('public_enrollment_enabled') !== null ? 1 : 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'id' => $tenant['id'],
                ]
            );
        });

        $this->audit('tenant.update', (string) $tenant['id'], $request->ip());

        return $this->redirectWithSuccess('/admin/parc', $name.' a ete mis a jour.');
    }

    /**
     * Suspend un etablissement.
     *
     * Ses comptes ne peuvent plus se connecter, mais rien n'est efface : une
     * suspension pour impaye doit pouvoir etre levee le lendemain sans qu'un
     * dossier ait disparu entre-temps.
     */
    public function suspend(Request $request): Response
    {
        $this->assertSuperAdmin();

        $tenant = $this->findTenant((string) $request->attribute('id'));
        $reason = trim($request->string('reason'));

        if ($reason === '') {
            return $this->redirectWithError('/admin/parc', 'Indiquez le motif de la suspension.');
        }

        $this->setPlatformStatus((string) $tenant['id'], 'SUSPENDED', $reason);
        $this->audit('tenant.suspend', (string) $tenant['id'], $request->ip());

        return $this->redirectWithSuccess('/admin/parc', $tenant['name'].' est suspendu.');
    }

    public function restore(Request $request): Response
    {
        $this->assertSuperAdmin();

        $tenant = $this->findTenant((string) $request->attribute('id'));

        $this->setPlatformStatus((string) $tenant['id'], 'ACTIVE', null);
        $this->audit('tenant.restore', (string) $tenant['id'], $request->ip());

        return $this->redirectWithSuccess('/admin/parc', $tenant['name'].' est de nouveau actif.');
    }

    /**
     * Retire un etablissement du parc.
     *
     * Suppression logique, et refusee tant que l'ecole compte des eleves : un
     * dossier scolaire ne s'efface pas sur un clic, et le retrait d'une ecole
     * qui a fonctionne doit passer par un archivage decide, pas par un bouton.
     */
    public function destroy(Request $request): Response
    {
        $this->assertSuperAdmin();

        $tenant = $this->findTenant((string) $request->attribute('id'));

        $students = (int) $this->app->tenant()->global(fn () => $this->app->db()->scalar(
            'SELECT COUNT(*) FROM students WHERE tenant_id = :tenant AND deleted_at IS NULL',
            ['tenant' => $tenant['id']]
        ));

        if ($students > 0) {
            return $this->redirectWithError(
                '/admin/parc',
                $tenant['name'].' compte '.$students.' eleves : suspendez-le plutot que de le retirer. '
                .'Les dossiers scolaires ne s effacent pas.'
            );
        }

        if (strtoupper($request->string('confirm')) !== strtoupper((string) $tenant['code'])) {
            return $this->redirectWithError(
                '/admin/parc',
                'Pour confirmer le retrait, saisissez le code de l etablissement.'
            );
        }

        $this->app->tenant()->global(function () use ($tenant): void {
            $now = date('Y-m-d H:i:s');

            $this->app->db()->execute(
                'UPDATE tenants SET deleted_at = :now, updated_at = :updated_at WHERE id = :id',
                ['now' => $now, 'updated_at' => $now, 'id' => $tenant['id']]
            );

            // Les comptes suivent : laisser une session ouverte sur un
            // etablissement retire serait une porte oubliee.
            $this->app->db()->execute(
                'UPDATE users SET deleted_at = :now, updated_at = :updated_at WHERE tenant_id = :tenant',
                ['now' => $now, 'updated_at' => $now, 'tenant' => $tenant['id']]
            );
        });

        $this->audit('tenant.delete', (string) $tenant['id'], $request->ip());

        return $this->redirectWithSuccess('/admin/parc', $tenant['name'].' a ete retire du parc.');
    }

    /** Journal des courriers envoyes, pour savoir ce qui est reellement parti. */
    public function notifications(Request $request): Response
    {
        $this->assertSuperAdmin();

        $notifications = $this->app->tenant()->global(fn () => $this->app->db()->select(
            'SELECT * FROM notifications ORDER BY created_at DESC LIMIT 100'
        ));

        return $this->view('platform.notifications', ['notifications' => $notifications]);
    }

    /** Reprend un courrier reste en echec. */
    public function retryNotification(Request $request): Response
    {
        $this->assertSuperAdmin();

        $id = (string) $request->attribute('id');
        $sent = $this->app->tenant()->global(fn (): bool => $this->app->mailer()->retry($id));

        return $sent
            ? $this->redirectWithSuccess('/admin/courriers', 'Le courrier est parti.')
            : $this->redirectWithError('/admin/courriers', 'La remise a echoue de nouveau.');
    }

    private function setPlatformStatus(string $tenantId, string $status, ?string $reason): void
    {
        $this->app->tenant()->global(function () use ($tenantId, $status, $reason): void {
            $tenant = $this->app->db()->selectOne(
                'SELECT config_json FROM tenants WHERE id = :id',
                ['id' => $tenantId]
            );

            $config = [];

            if (is_string($tenant['config_json'] ?? null) && $tenant['config_json'] !== '') {
                $decoded = json_decode((string) $tenant['config_json'], true);
                $config = is_array($decoded) ? $decoded : [];
            }

            // Le motif est conserve : une suspension sans raison retrouvee six
            // mois plus tard est ingerable.
            $config['suspension'] = $status === 'SUSPENDED'
                ? ['reason' => $reason, 'at' => date('Y-m-d H:i:s')]
                : null;

            $this->app->db()->execute(
                'UPDATE tenants SET platform_status = :status, config_json = :config, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'status' => $status,
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'id' => $tenantId,
                ]
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function findTenant(string $id): array
    {
        $tenant = $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
            'SELECT * FROM tenants WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        ));

        if ($tenant === null) {
            throw new HttpException(404);
        }

        return $tenant;
    }

    private function assertSuperAdmin(): void
    {
        if (! $this->app->rbac()->isSuperAdmin()) {
            throw new HttpException(403, 'Reserve au Super Admin.');
        }
    }

    /**
     * Trace des actes du Super Admin sur le parc.
     *
     * Creer, suspendre ou retirer un etablissement engage des dossiers
     * scolaires : ces actes doivent pouvoir etre rapportes a quelqu'un.
     */
    private function audit(string $action, string $resourceId, ?string $ip): void
    {
        $this->app->tenant()->global(function () use ($action, $resourceId, $ip): void {
            $this->app->db()->execute(
                'INSERT INTO audit_logs (id, user_id, action, resource, resource_id, ip_address, timestamp)
                 VALUES (:id, :user, :action, :resource, :resource_id, :ip, :timestamp)',
                [
                    'id' => Table::uuid(),
                    'user' => $this->app->auth()->id(),
                    'action' => $action,
                    'resource' => 'tenants',
                    'resource_id' => $resourceId,
                    'ip' => $ip,
                    'timestamp' => date('Y-m-d H:i:s'),
                ]
            );
        });
    }
}
