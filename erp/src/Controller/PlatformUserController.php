<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Auth\Auth;
use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Platform\PlatformStats;
use Scholaris\Platform\Scope;
use Scholaris\Support\Cameroon;

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
                        locked_until = NULL, must_change_password = 1, updated_at = :updated_at
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

    /**
     * Retire un compte.
     *
     * Suppression logique : les actes deja poses par ce compte — notes
     * saisies, paiements encaisses, entrees du journal — continuent de lui
     * etre rattaches. Un journal d'audit qui renvoie vers un auteur disparu ne
     * vaut plus rien, et c'est precisement quand quelqu'un part que l'on
     * cherche a savoir ce qu'il a fait.
     *
     * Trois refus, dans cet ordre : soi-meme, le dernier administrateur, et un
     * compte encore rattache a un dossier eleve.
     */
    public function destroy(Request $request): Response
    {
        $this->assertSuperAdmin();

        $user = $this->findUser((string) $request->attribute('id'));

        if ((string) $user['id'] === (string) $this->app->auth()->id()) {
            return $this->redirectWithError('/admin/comptes', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        if ($this->isLastPlatformAdmin($user)) {
            return $this->redirectWithError(
                '/admin/comptes',
                'C est le dernier administrateur de la plateforme : nommez-en un autre avant de le supprimer.'
            );
        }

        // La confirmation est l'adresse elle-meme : une case a cocher se coche
        // par megarde, une adresse se recopie deliberement.
        if (strtolower(trim($request->string('confirm'))) !== strtolower((string) $user['email'])) {
            return $this->redirectWithError(
                '/admin/comptes',
                'Pour confirmer la suppression, saisissez l adresse email du compte.'
            );
        }

        $linked = $this->linkedRecord($user);

        if ($linked !== null) {
            return $this->redirectWithError(
                '/admin/comptes',
                'Ce compte est rattache a '.$linked.'. Desactivez-le plutot que de le supprimer : '
                .'le dossier resterait sans titulaire.'
            );
        }

        $this->app->tenant()->global(function () use ($user): void {
            $now = date('Y-m-d H:i:s');

            $this->app->db()->execute(
                'UPDATE users SET deleted_at = :now, status = :status, updated_at = :updated_at WHERE id = :id',
                ['now' => $now, 'status' => 'INACTIVE', 'updated_at' => $now, 'id' => $user['id']]
            );

            // Les habilitations partent avec le compte : un role laisse en
            // place redonnerait tous ses droits a une eventuelle
            // reactivation, sans que personne ne l'ait decide.
            $this->app->db()->execute(
                'DELETE FROM user_roles WHERE user_id = :id',
                ['id' => $user['id']]
            );
        });

        $this->trail()->deleted('user.delete', 'users', (string) $user['id'], [
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'tenant_id' => $user['tenant_id'],
        ]);

        return $this->redirectWithSuccess('/admin/comptes', $user['email'].' a ete supprime.');
    }

    /**
     * Le compte est-il titulaire d'un dossier qui deviendrait orphelin ?
     *
     * Supprimer le compte d'un eleve laisserait son dossier scolaire sans
     * acces ; celui d'un parent romprait le lien avec ses enfants.
     */
    private function linkedRecord(array $user): ?string
    {
        return $this->app->tenant()->global(function () use ($user): ?string {
            $student = $this->app->db()->scalar(
                'SELECT matricule FROM students WHERE user_id = :id AND deleted_at IS NULL',
                ['id' => $user['id']]
            );

            if ($student !== null) {
                return 'un dossier eleve ('.$student.')';
            }

            $parent = $this->app->db()->scalar(
                'SELECT id FROM parents WHERE user_id = :id',
                ['id' => $user['id']]
            );

            if ($parent !== null) {
                return 'une fiche parent';
            }

            $employee = $this->app->db()->scalar(
                "SELECT id FROM employees WHERE user_id = :id AND status = 'ACTIVE'",
                ['id' => $user['id']]
            );

            return $employee !== null ? 'un dossier du personnel en activite' : null;
        });
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
            'regions' => Cameroon::regionChoices(),
            'departments' => Cameroon::departments(),
            'ministries' => Cameroon::ministries(),
        ]);
    }

    /**
     * Change le perimetre d'un compte de pilotage.
     *
     * C'est le maillon qui manquait : le perimetre existait dans le code et
     * cadrait deja toutes les lectures, mais rien ne permettait de l'attribuer.
     * Un delegue regional ne pouvait donc pas exister autrement qu'en
     * modifiant la base a la main.
     *
     * Restreindre est toujours possible ; elargir jusqu'au national ne l'est
     * que pour un compte qui n'appartient a aucun etablissement — un
     * administrateur d'ecole ne doit pas pouvoir se voir accorder la vue
     * nationale par un formulaire.
     */
    public function updateScope(Request $request): Response
    {
        $this->assertSuperAdmin();

        $user = $this->findUser((string) $request->attribute('id'));
        $type = $request->string('scope_type');
        $value = trim($request->string('scope_value'));

        if ($type === Scope::PLATFORM) {
            if (($user['tenant_id'] ?? null) !== null) {
                return $this->redirectWithError(
                    '/admin/comptes',
                    'Ce compte appartient a un etablissement : il ne peut pas recevoir le perimetre national.'
                );
            }

            $this->setScope((string) $user['id'], null, null);

            return $this->redirectWithSuccess('/admin/comptes', $user['email'].' couvre desormais tout le territoire.');
        }

        if ($type === Scope::MINISTRY) {
            if (! Cameroon::isMinistry($value)) {
                return $this->redirectWithError('/admin/comptes', 'Ministere inconnu.');
            }

            $this->setScope((string) $user['id'], Scope::MINISTRY, $value);

            return $this->redirectWithSuccess(
                '/admin/comptes',
                $user['email'].' couvre la tutelle '.$value.'.'
            );
        }

        if ($type === Scope::REGION) {
            if (! Cameroon::isRegion($value)) {
                return $this->redirectWithError('/admin/comptes', 'Region inconnue.');
            }

            $this->setScope((string) $user['id'], Scope::REGION, $value);

            return $this->redirectWithSuccess(
                '/admin/comptes',
                $user['email'].' couvre la region '.Cameroon::regionName($value).'.'
            );
        }

        if ($type === Scope::DEPARTMENT) {
            // Verifie contre le referentiel : une orthographe libre ne
            // correspondrait a aucun etablissement, et le compte se
            // retrouverait devant des ecrans vides sans comprendre pourquoi.
            if (! Cameroon::isDepartment($value)) {
                return $this->redirectWithError(
                    '/admin/comptes',
                    'Departement inconnu. Choisissez-en un parmi les cinquante-huit du pays.'
                );
            }

            $this->setScope((string) $user['id'], Scope::DEPARTMENT, $value);

            return $this->redirectWithSuccess('/admin/comptes', $user['email'].' couvre le departement '.$value.'.');
        }

        return $this->redirectWithError('/admin/comptes', 'Perimetre inconnu.');
    }

    private function setScope(string $userId, ?string $type, ?string $value): void
    {
        $before = $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
            'SELECT scope_type, scope_value FROM users WHERE id = :id',
            ['id' => $userId]
        ));

        $this->app->tenant()->global(function () use ($userId, $type, $value): void {
            $this->app->db()->execute(
                'UPDATE users SET scope_type = :type, scope_value = :value, updated_at = :updated_at WHERE id = :id',
                ['type' => $type, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $userId]
            );
        });

        // Elargir ou restreindre ce que quelqu'un peut voir est un acte de
        // gouvernance : il doit pouvoir etre rapporte a son auteur.
        $this->trail()->changed(
            'user.scope',
            'users',
            $userId,
            ['scope_type' => $before['scope_type'] ?? null, 'scope_value' => $before['scope_value'] ?? null],
            ['scope_type' => $type, 'scope_value' => $value],
            ['scope_type', 'scope_value']
        );
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

        // Perimetre demande a la creation : sans lui, tout nouveau compte de
        // pilotage serait national, et il faudrait le restreindre apres coup —
        // avec la fenetre d'acces total que cela laisse ouverte entre les deux.
        $scopeType = $request->string('scope_type');
        $scopeValue = trim($request->string('scope_value'));

        if ($scopeType === Scope::MINISTRY && Cameroon::isMinistry($scopeValue)) {
            $scope = [Scope::MINISTRY, $scopeValue];
        } elseif ($scopeType === Scope::REGION && Cameroon::isRegion($scopeValue)) {
            $scope = [Scope::REGION, $scopeValue];
        } elseif ($scopeType === Scope::DEPARTMENT && Cameroon::isDepartment($scopeValue)) {
            $scope = [Scope::DEPARTMENT, $scopeValue];
        } else {
            $scope = [null, null];
        }

        // Un delegue n'administre pas la plateforme : il la consulte sur son
        // territoire. Lui donner SUPER_ADMIN lui ouvrirait la creation
        // d'etablissements et la gestion des comptes.
        // Une tutelle ministerielle ouvre la creation d'etablissements sur son
        // perimetre ; une delegation territoriale, non — elle constate, elle
        // n'ouvre pas d'ecole.
        $roleName = match ($scope[0]) {
            null => 'SUPER_ADMIN',
            Scope::MINISTRY => 'Admin Ministère',
            default => 'Délégué',
        };

        $this->app->tenant()->global(function () use ($userId, $email, $firstName, $lastName, $password, $scope, $roleName): void {
            $now = date('Y-m-d H:i:s');

            $this->app->db()->execute(
                'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status,
                        must_change_password, scope_type, scope_value, created_at, updated_at)
                 VALUES (:id, NULL, :email, :hash, :first, :last, :status, 1, :scope_type, :scope_value, :created_at, :updated_at)',
                [
                    'id' => $userId,
                    'email' => $email,
                    'hash' => Auth::hash($password),
                    'first' => $firstName,
                    'last' => $lastName,
                    'status' => 'ACTIVE',
                    'scope_type' => $scope[0],
                    'scope_value' => $scope[1],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $roleId = $this->app->db()->scalar(
                'SELECT id FROM roles WHERE tenant_id IS NULL AND name = :name',
                ['name' => $roleName]
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
