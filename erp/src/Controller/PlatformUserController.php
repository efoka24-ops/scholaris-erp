<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Auth\Auth;
use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Platform\PlatformStats;

/**
 * Comptes, vus depuis la plateforme.
 *
 * Un directeur qui perd son mot de passe, un compte a desactiver apres un
 * depart, un second administrateur de plateforme a nommer : rien de tout cela
 * n'etait possible sans ouvrir la base. Ce sont pourtant les demandes les plus
 * frequentes qui remontent a un administrateur national.
 *
 * Trois precautions structurent cet ecran.
 *
 * Les lectures traversent les etablissements, donc explicitement hors scope.
 *
 * Un mot de passe n'est jamais consulte, seulement remplace : il n'existe
 * nulle part en clair, et c'est ce qui doit rester vrai.
 *
 * Enfin, le Super Admin ne peut ni se desactiver, ni se retirer lui-meme le
 * dernier acces de plateforme : une plateforme sans administrateur ne se
 * repare que dans la base.
 */
final class PlatformUserController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $this->assertSuperAdmin();

        $search = trim($request->string('q'));
        $scope = $request->string('perimetre');
        $page = max(1, (int) $request->string('page', '1'));

        $where = ['u.deleted_at IS NULL'];
        $params = [];

        if ($search !== '') {
            $where[] = '(LOWER(u.email) LIKE :s1 OR LOWER(u.last_name) LIKE :s2 OR LOWER(u.first_name) LIKE :s3)';
            $needle = '%'.strtolower($search).'%';
            // Trois marqueurs distincts : MySQL refuse qu'un meme nom soit
            // reutilise dans une requete preparee.
            $params['s1'] = $needle;
            $params['s2'] = $needle;
            $params['s3'] = $needle;
        }

        if ($scope === 'plateforme') {
            $where[] = 'u.tenant_id IS NULL';
        } elseif ($scope !== '' && $scope !== 'tous') {
            $where[] = 'u.tenant_id = :tenant';
            $params['tenant'] = $scope;
        }

        $clause = implode(' AND ', $where);

        $data = $this->app->tenant()->global(function () use ($clause, $params, $page): array {
            $total = (int) $this->app->db()->scalar(
                'SELECT COUNT(*) FROM users u WHERE '.$clause,
                $params
            );

            $users = $this->app->db()->select(
                'SELECT u.*, t.name AS tenant_name, t.code AS tenant_code,
                        (SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                          WHERE ur.user_id = u.id LIMIT 1) AS role_name
                 FROM users u
                 LEFT JOIN tenants t ON t.id = u.tenant_id
                 WHERE '.$clause.'
                 ORDER BY u.last_name, u.first_name
                 LIMIT '.self::PER_PAGE.' OFFSET '.(($page - 1) * self::PER_PAGE),
                $params
            );

            return [
                'users' => $users,
                'total' => $total,
                'tenants' => $this->app->db()->select(
                    'SELECT id, code, name FROM tenants WHERE deleted_at IS NULL ORDER BY name'
                ),
                // Un compte cree n'est pas un compte utilise : l'ecart entre
                // les deux est ce qu'un administrateur national doit
                // surveiller.
                'profiles' => (new PlatformStats($this->app->db()))->accountsByProfile(),
            ];
        });

        return $this->view('platform.users-index', [
            'users' => $data['users'],
            'total' => $data['total'],
            'tenants' => $data['tenants'],
            'profiles' => $data['profiles'],
            'search' => $search,
            'scope' => $scope,
            'page' => $page,
            'perPage' => self::PER_PAGE,
        ]);
    }

    /**
     * Remplace le mot de passe d'un compte.
     *
     * Le nouveau mot de passe est affiche une fois et n'est stocke que
     * hache : un administrateur ne consulte jamais le mot de passe de
     * quelqu'un, il lui en attribue un nouveau.
     */
    public function resetPassword(Request $request): Response
    {
        $this->assertSuperAdmin();

        $user = $this->findUser((string) $request->attribute('id'));
        $password = $this->generatePassword();

        $this->app->tenant()->global(function () use ($user, $password): void {
            $this->app->db()->execute(
                'UPDATE users SET password_hash = :hash, failed_login_attempts = 0,
                        locked_until = NULL, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'hash' => Auth::hash($password),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'id' => $user['id'],
                ]
            );
        });

        $this->audit('user.reset_password', (string) $user['id'], $request->ip());

        // Le courrier part si une adresse de messagerie est configuree ; le
        // mot de passe reste affiche a l'ecran de toute facon, faute de quoi
        // un echec de remise laisserait le compte inaccessible.
        $this->app->mailer()->send(
            (string) $user['email'],
            'Votre mot de passe SCHOLARIS a ete reinitialise',
            implode("\n", [
                'Bonjour '.$user['first_name'].' '.$user['last_name'].',',
                '',
                'Un nouveau mot de passe a ete attribue a votre compte.',
                '',
                'Identifiant : '.$user['email'],
                'Mot de passe provisoire : '.$password,
                '',
                'Changez-le des votre prochaine connexion : il a circule par',
                'courrier electronique, il ne doit pas rester en service.',
            ]),
            'user',
            (string) $user['id'],
            $user['tenant_id'] ?? null
        );

        return $this->view('platform.user-password', [
            'user' => $user,
            'password' => $password,
        ]);
    }

    public function deactivate(Request $request): Response
    {
        $this->assertSuperAdmin();

        $user = $this->findUser((string) $request->attribute('id'));

        // Se desactiver soi-meme ferme la porte de l'interieur.
        if ((string) $user['id'] === (string) $this->app->auth()->id()) {
            return $this->redirectWithError('/admin/comptes', 'Vous ne pouvez pas desactiver votre propre compte.');
        }

        if ($this->isLastPlatformAdmin($user)) {
            return $this->redirectWithError(
                '/admin/comptes',
                'C est le dernier administrateur de la plateforme : nommez-en un autre avant de le desactiver.'
            );
        }

        $this->setStatus((string) $user['id'], 'INACTIVE');
        $this->audit('user.deactivate', (string) $user['id'], $request->ip());

        return $this->redirectWithSuccess('/admin/comptes', $user['email'].' ne peut plus se connecter.');
    }

    public function activate(Request $request): Response
    {
        $this->assertSuperAdmin();

        $user = $this->findUser((string) $request->attribute('id'));

        $this->setStatus((string) $user['id'], 'ACTIVE');
        $this->audit('user.activate', (string) $user['id'], $request->ip());

        return $this->redirectWithSuccess('/admin/comptes', $user['email'].' peut de nouveau se connecter.');
    }

    /** Leve un verrouillage du a des tentatives de connexion repetees. */
    public function unlock(Request $request): Response
    {
        $this->assertSuperAdmin();

        $user = $this->findUser((string) $request->attribute('id'));

        $this->app->tenant()->global(function () use ($user): void {
            $this->app->db()->execute(
                'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, updated_at = :updated_at
                 WHERE id = :id',
                ['updated_at' => date('Y-m-d H:i:s'), 'id' => $user['id']]
            );
        });

        $this->audit('user.unlock', (string) $user['id'], $request->ip());

        return $this->redirectWithSuccess('/admin/comptes', 'Le verrouillage de '.$user['email'].' est leve.');
    }

    public function createForm(Request $request): Response
    {
        $this->assertSuperAdmin();

        return $this->view('platform.user-create', [
            'old' => $this->app->session()->pullOldInput(),
        ]);
    }

    /**
     * Nomme un second administrateur de plateforme.
     *
     * Un seul compte d'administration nationale est un point de defaillance :
     * s'il se perd, la plateforme n'a plus d'arbitre pour les demandes.
     */
    public function store(Request $request): Response
    {
        $this->assertSuperAdmin();

        $email = strtolower(trim($request->string('email')));
        $firstName = trim($request->string('first_name'));
        $lastName = trim($request->string('last_name'));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $firstName === '' || $lastName === '') {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/admin/comptes/creer', 'Renseignez un nom, un prenom et une adresse email valide.');
        }

        $exists = $this->app->tenant()->global(fn () => $this->app->db()->scalar(
            'SELECT id FROM users WHERE tenant_id IS NULL AND email = :email',
            ['email' => $email]
        ));

        if ($exists !== null) {
            $this->app->session()->flashInput($request->all());

            return $this->redirectWithError('/admin/comptes/creer', 'Un compte de plateforme porte deja cette adresse.');
        }

        $password = $this->generatePassword();
        $userId = Table::uuid();

        $this->app->tenant()->global(function () use ($userId, $email, $firstName, $lastName, $password): void {
            $now = date('Y-m-d H:i:s');

            $this->app->db()->execute(
                'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status, created_at, updated_at)
                 VALUES (:id, NULL, :email, :hash, :first, :last, :status, :created_at, :updated_at)',
                [
                    'id' => $userId,
                    'email' => $email,
                    'hash' => Auth::hash($password),
                    'first' => $firstName,
                    'last' => $lastName,
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $roleId = $this->app->db()->scalar(
                'SELECT id FROM roles WHERE tenant_id IS NULL AND name = :name',
                ['name' => 'SUPER_ADMIN']
            );

            if ($roleId === null) {
                throw new HttpException(500, 'Referentiel des roles absent.');
            }

            $this->app->db()->execute(
                'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
                ['user' => $userId, 'role' => $roleId]
            );
        });

        $this->audit('user.create_platform_admin', $userId, $request->ip());

        $this->app->mailer()->send(
            $email,
            'Votre acces administrateur SCHOLARIS',
            implode("\n", [
                'Bonjour '.$firstName.' '.$lastName.',',
                '',
                'Un acces d administration de la plateforme vous a ete ouvert.',
                '',
                'Identifiant : '.$email,
                'Mot de passe provisoire : '.$password,
                '',
                'Changez-le des votre premiere connexion.',
            ]),
            'user',
            $userId
        );

        return $this->view('platform.user-password', [
            'user' => [
                'id' => $userId,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'tenant_name' => null,
            ],
            'password' => $password,
        ]);
    }

    /**
     * Le compte est-il le dernier acces d'administration de la plateforme ?
     *
     * @param  array<string, mixed>  $user
     */
    private function isLastPlatformAdmin(array $user): bool
    {
        if (($user['tenant_id'] ?? null) !== null) {
            return false;
        }

        $others = (int) $this->app->tenant()->global(fn () => $this->app->db()->scalar(
            "SELECT COUNT(*) FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.name = 'SUPER_ADMIN' AND u.deleted_at IS NULL
               AND u.status = 'ACTIVE' AND u.id <> :id",
            ['id' => $user['id']]
        ));

        return $others === 0;
    }

    private function setStatus(string $userId, string $status): void
    {
        $this->app->tenant()->global(function () use ($userId, $status): void {
            $this->app->db()->execute(
                'UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id',
                ['status' => $status, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $userId]
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function findUser(string $id): array
    {
        $user = $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
            'SELECT u.*, t.name AS tenant_name FROM users u
             LEFT JOIN tenants t ON t.id = u.tenant_id
             WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $id]
        ));

        if ($user === null) {
            throw new HttpException(404);
        }

        return $user;
    }

    /** Sans caracteres ambigus : il sera dicte au telephone. */
    private function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $password = '';

        for ($i = 0; $i < 12; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    private function assertSuperAdmin(): void
    {
        if (! $this->app->rbac()->isSuperAdmin()) {
            throw new HttpException(403, 'Reserve au Super Admin.');
        }
    }

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
                    'resource' => 'users',
                    'resource_id' => $resourceId,
                    'ip' => $ip,
                    'timestamp' => date('Y-m-d H:i:s'),
                ]
            );
        });
    }
}
