<?php

declare(strict_types=1);

namespace Scholaris\Auth;

use Scholaris\Database\Connection;
use Scholaris\Database\Table;
use Scholaris\Security\Session;
use Scholaris\Tenant\TenantContext;

/**
 * Authentification et session applicative.
 *
 * Les mots de passe sont verifies avec password_verify sur un hachage bcrypt.
 * Le compte se verrouille apres cinq echecs consecutifs, ce qui rend une
 * attaque par force brute impraticable sur un ERP dont les emails sont connus.
 */
final class Auth
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    private Connection $db;

    private Session $session;

    private TenantContext $tenant;

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    public function __construct(Connection $db, Session $session, TenantContext $tenant)
    {
        $this->db = $db;
        $this->session = $session;
        $this->tenant = $tenant;
    }

    /**
     * Verifie les identifiants et ouvre la session.
     *
     * @return array{ok: bool, error?: string}
     */
    public function attempt(string $email, string $password, ?string $tenantCode, ?string $ip): array
    {
        $user = $this->findUser($email, $tenantCode);

        if ($user === null) {
            return ['ok' => false, 'error' => 'Identifiants incorrects.'];
        }

        if ($this->isLocked($user)) {
            return ['ok' => false, 'error' => 'Compte temporairement verrouille. Reessayez dans quelques minutes.'];
        }

        if (! password_verify($password, (string) $user['password_hash'])) {
            $this->registerFailure($user);

            return ['ok' => false, 'error' => 'Identifiants incorrects.'];
        }

        if ($user['status'] !== 'ACTIVE') {
            return ['ok' => false, 'error' => 'Ce compte est desactive. Contactez votre administration.'];
        }

        // Un etablissement suspendu ne laisse plus entrer personne. Sans ce
        // controle, la suspension ne serait qu'un libelle dans une liste.
        if ($this->tenantIsSuspended($user)) {
            return [
                'ok' => false,
                'error' => 'L acces de votre etablissement est suspendu. Contactez l administration de la plateforme.',
            ];
        }

        $this->completeLogin($user, $ip);

        return ['ok' => true];
    }

    /**
     * L'etablissement du compte est-il suspendu ?
     *
     * Le Super Admin n'appartient a aucun etablissement : il n'est jamais
     * concerne, ce qui est heureux puisque c'est lui qui leve les suspensions.
     *
     * @param  array<string, mixed>  $user
     */
    private function tenantIsSuspended(array $user): bool
    {
        $tenantId = $user['tenant_id'] ?? null;

        if (! is_string($tenantId) || $tenantId === '') {
            return false;
        }

        $status = $this->db->scalar(
            'SELECT platform_status FROM tenants WHERE id = :id',
            ['id' => $tenantId]
        );

        return is_string($status) && $status === 'SUSPENDED';
    }

    /**
     * Recherche le compte. Sans code d'etablissement, l'email doit etre unique
     * sur toute la plateforme : un meme email peut exister dans plusieurs
     * etablissements, et choisir arbitrairement serait une faille de logique.
     *
     * @return array<string, mixed>|null
     */
    private function findUser(string $email, ?string $tenantCode): ?array
    {
        if ($tenantCode !== null && $tenantCode !== '') {
            return $this->db->selectOne(
                'SELECT u.* FROM users u
                 INNER JOIN tenants t ON t.id = u.tenant_id
                 WHERE u.email = :email AND t.code = :code AND u.deleted_at IS NULL
                 LIMIT 1',
                ['email' => $email, 'code' => $tenantCode]
            );
        }

        $matches = $this->db->select(
            'SELECT * FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 2',
            ['email' => $email]
        );

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param  array<string, mixed>  $user
     */
    public function isLocked(array $user): bool
    {
        $lockedUntil = $user['locked_until'] ?? null;

        return is_string($lockedUntil) && $lockedUntil !== '' && strtotime($lockedUntil) > time();
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function registerFailure(array $user): void
    {
        $attempts = (int) ($user['failed_login_attempts'] ?? 0) + 1;

        $this->db->execute(
            'UPDATE users SET failed_login_attempts = :attempts, locked_until = :locked WHERE id = :id',
            [
                'attempts' => $attempts,
                'locked' => $attempts >= self::MAX_ATTEMPTS
                    ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60)
                    : null,
                'id' => $user['id'],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function completeLogin(array $user, ?string $ip): void
    {
        $this->db->execute(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login = :now WHERE id = :id',
            ['now' => date('Y-m-d H:i:s'), 'id' => $user['id']]
        );

        // Identifiant de session renouvele avant d'y inscrire l'utilisateur :
        // un identifiant obtenu avant connexion ne doit pas rester valable.
        $this->session->regenerate();
        $this->session->set('user_id', $user['id']);
        $this->session->set('tenant_id', $user['tenant_id']);

        // Un administrateur de plateforme n'appartient a aucun etablissement :
        // le contexte reste vide, et toute lecture scopee echouera tant qu'il
        // n'aura pas choisi un etablissement. Le defaut reste le refus.
        if ($user['tenant_id'] !== null) {
            $this->tenant->set((string) $user['tenant_id']);
        }

        $this->user = $user;

        $this->db->execute(
            'INSERT INTO audit_logs (id, user_id, action, resource, ip_address, timestamp)
             VALUES (:id, :user_id, :action, :resource, :ip, :now)',
            [
                'id' => Table::uuid(),
                'user_id' => $user['id'],
                'action' => 'login',
                'resource' => 'auth',
                'ip' => $ip,
                'now' => date('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Recharge l'utilisateur connecte depuis la session, et repositionne
     * l'etablissement courant. Appele au debut de chaque requete.
     */
    public function restore(): void
    {
        $userId = $this->session->get('user_id');

        if (! is_string($userId) || $userId === '') {
            return;
        }

        $user = $this->db->selectOne(
            'SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $userId]
        );

        // Compte supprime ou desactive depuis l'ouverture de session : la
        // session est invalidee immediatement plutot qu'a sa prochaine
        // expiration naturelle.
        if ($user === null || $user['status'] !== 'ACTIVE') {
            $this->logout();

            return;
        }

        $this->user = $user;

        if ($user['tenant_id'] !== null) {
            $this->tenant->set((string) $user['tenant_id']);

            return;
        }

        // Compte de plateforme : le contexte reste vide, sauf s'il s'est
        // explicitement place dans un etablissement pour le consulter.
        $impersonated = $this->session->get('impersonated_tenant_id');

        if (is_string($impersonated) && $impersonated !== '') {
            $this->tenant->set($impersonated);
        }
    }

    /**
     * Vrai pour un compte de plateforme, qui n'appartient a aucune ecole.
     */
    public function isPlatformAccount(): bool
    {
        return $this->user !== null && ($this->user['tenant_id'] ?? null) === null;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $this->user;
    }

    public function id(): ?string
    {
        return isset($this->user['id']) ? (string) $this->user['id'] : null;
    }

    public function logout(): void
    {
        $this->user = null;
        $this->tenant->clear();
        $this->session->destroy();
    }

    /** Hachage bcrypt, cout par defaut de PHP. */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}
