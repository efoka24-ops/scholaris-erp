<?php

declare(strict_types=1);

namespace Scholaris\Auth;

use Scholaris\Database\Connection;

/**
 * Habilitations : 122 permissions reparties sur 12 roles metier.
 *
 * Les permissions de l'utilisateur sont chargees une fois par requete puis
 * conservees en memoire : sans cela, chaque verification declencherait une
 * jointure a trois tables, et une page listant vingt actions en ferait vingt.
 */
final class Rbac
{
    private Connection $db;

    private Auth $auth;

    /** @var array<string, true>|null Ensemble "ressource:action" de l'utilisateur. */
    private ?array $permissions = null;

    /** @var list<string>|null */
    private ?array $roles = null;

    public function __construct(Connection $db, Auth $auth)
    {
        $this->db = $db;
        $this->auth = $auth;
    }

    /** Vide le cache, apres un changement de role ou une reconnexion. */
    public function reset(): void
    {
        $this->permissions = null;
        $this->roles = null;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }

        $userId = $this->auth->id();

        if ($userId === null) {
            return $this->roles = [];
        }

        $rows = $this->db->select(
            'SELECT r.name FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :user_id',
            ['user_id' => $userId]
        );

        return $this->roles = array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function hasRole(string $name): bool
    {
        return in_array($name, $this->roles(), true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('SUPER_ADMIN');
    }

    /**
     * @return array<string, true>
     */
    private function permissions(): array
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }

        $userId = $this->auth->id();

        if ($userId === null) {
            return $this->permissions = [];
        }

        $rows = $this->db->select(
            'SELECT DISTINCT p.resource, p.action FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :user_id',
            ['user_id' => $userId]
        );

        $set = [];

        foreach ($rows as $row) {
            $set[$row['resource'].':'.$row['action']] = true;
        }

        return $this->permissions = $set;
    }

    /**
     * Verifie une permission exprimee "ressource:action".
     *
     * L'action "manage" sur une ressource vaut pour toutes ses actions, et le
     * Super Admin traverse le controle : il administre la plateforme entiere.
     */
    public function allows(string $permission): bool
    {
        if (! $this->auth->check()) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        $permissions = $this->permissions();

        if (isset($permissions[$permission])) {
            return true;
        }

        $separator = strpos($permission, ':');

        if ($separator === false) {
            return false;
        }

        return isset($permissions[substr($permission, 0, $separator).':manage']);
    }

    public function denies(string $permission): bool
    {
        return ! $this->allows($permission);
    }
}
