<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Audit\Contracts\SubjectDataSource;

/**
 * Compte et appareils d'une personne concernée (jamais le hachage du mot de passe ni les secrets de double authentification).
 * L'effacement désactive le compte, retire ses identifiants de connexion et révoque jetons, appareils et codes.
 */
final class PrivacySubjectSource implements SubjectDataSource
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function name(): string
    {
        return 'identity';
    }

    public function collect(string $tenantId, string $personId): array
    {
        return [
            'users' => $this->rows('SELECT id, phone, email, locale, status, last_login_at, created_at FROM users WHERE tenant_id = ? AND person_id = ? ORDER BY id', [$tenantId, $personId]),
            'role_assignments' => $this->rows('SELECT id, role, scope_type, scope_id, establishment_id, starts_at, ends_at, created_at FROM role_assignments WHERE tenant_id = ? AND person_id = ? ORDER BY id', [$tenantId, $personId]),
            'devices' => $this->rows('SELECT d.id, d.name, d.location, d.ip, d.last_seen_at, d.created_at FROM devices d WHERE d.tenant_id = ? AND d.user_id IN (SELECT id FROM users WHERE tenant_id = ? AND person_id = ?) ORDER BY d.id', [$tenantId, $tenantId, $personId]),
        ];
    }

    public function anonymize(string $tenantId, string $personId): int
    {
        $users = 'SELECT id FROM users WHERE tenant_id = ? AND person_id = ?';
        $touched = 0;
        $touched += $this->run("DELETE FROM access_tokens WHERE tenant_id = ? AND user_id IN ({$users})", [$tenantId, $tenantId, $personId]);
        $touched += $this->run("DELETE FROM devices WHERE tenant_id = ? AND user_id IN ({$users})", [$tenantId, $tenantId, $personId]);
        $touched += $this->run("DELETE FROM backup_codes WHERE tenant_id = ? AND user_id IN ({$users})", [$tenantId, $tenantId, $personId]);
        $touched += $this->run("DELETE FROM one_time_codes WHERE tenant_id = ? AND user_id IN ({$users})", [$tenantId, $tenantId, $personId]);
        $touched += $this->run(
            "UPDATE users SET status = 'locked', phone = NULL, email = NULL, two_factor_secret = NULL, two_factor_confirmed_at = NULL WHERE tenant_id = ? AND person_id = ?",
            [$tenantId, $personId],
        );

        return $touched;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /** @param list<scalar|null> $params */
    private function run(string $sql, array $params): int
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }
}
