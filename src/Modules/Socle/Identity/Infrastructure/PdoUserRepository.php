<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Domain\User;

final class PdoUserRepository extends PdoRepository
{
    public function find(string $tenantId, string $userId): ?User
    {
        $row = $this->one('SELECT * FROM users WHERE tenant_id = ? AND id = ?', [$tenantId, $userId]);

        return $row === null ? null : self::map($row);
    }

    public function findByPerson(string $tenantId, string $personId): ?User
    {
        $row = $this->one('SELECT * FROM users WHERE tenant_id = ? AND person_id = ?', [$tenantId, $personId]);

        return $row === null ? null : self::map($row);
    }

    /**
     * Recherche à la connexion, avant de connaître le client : un même identifiant peut exister dans
     * plusieurs clients, le mot de passe départage.
     *
     * @return list<User>
     */
    public function findByIdentifier(Identifier $identifier): array
    {
        if (!$identifier->valid) {
            return [];
        }
        $column = $identifier->isEmail ? 'email' : 'phone';

        return array_map(
            self::map(...),
            $this->rows('SELECT * FROM users WHERE ' . $column . ' = ? ORDER BY created_at, id', [$identifier->value]),
        );
    }

    public function phoneTaken(string $tenantId, string $phone): bool
    {
        return $this->one('SELECT id FROM users WHERE tenant_id = ? AND phone = ?', [$tenantId, $phone]) !== null;
    }

    public function emailTaken(string $tenantId, string $email): bool
    {
        return $this->one('SELECT id FROM users WHERE tenant_id = ? AND email = ?', [$tenantId, $email]) !== null;
    }

    public function insertPending(string $tenantId, string $personId, ?string $phone, ?string $email, string $locale, string $now): string
    {
        $id = (string) new Ulid();
        $this->run(
            'INSERT INTO users (id, tenant_id, person_id, phone, email, locale, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $personId, $phone, $email, $locale, User::PENDING, $now, $now],
        );

        return $id;
    }

    public function setPassword(string $tenantId, string $userId, string $hash, string $now): void
    {
        $this->run(
            'UPDATE users SET password_hash = ?, status = ?, must_change_password = 0, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$hash, User::ACTIVE, $now, $tenantId, $userId],
        );
    }

    /** Mot de passe provisoire (haché) : compte actif, changement obligatoire à la première connexion. */
    public function setProvisionalPassword(string $tenantId, string $userId, string $hash, string $now): void
    {
        $this->run(
            'UPDATE users SET password_hash = ?, status = ?, must_change_password = 1, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$hash, User::ACTIVE, $now, $tenantId, $userId],
        );
    }

    /** Mot de passe choisi par la personne elle-même : le statut ne change pas. */
    public function changePassword(string $tenantId, string $userId, string $hash, string $now): void
    {
        $this->run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$hash, $now, $tenantId, $userId],
        );
    }

    /** Efface l'authentificateur du compte (la personne en enrôle un nouveau à sa prochaine connexion si son rôle l'exige). */
    public function clearTwoFactor(string $tenantId, string $userId, string $now): void
    {
        $this->run(
            'UPDATE users SET two_factor_secret = NULL, two_factor_confirmed_at = NULL, two_factor_last_step = 0, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$now, $tenantId, $userId],
        );
    }

    public function setStatus(string $tenantId, string $userId, string $status, string $now): void
    {
        $this->run('UPDATE users SET status = ?, updated_at = ? WHERE tenant_id = ? AND id = ?', [$status, $now, $tenantId, $userId]);
    }

    public function touchLogin(string $tenantId, string $userId, string $now): void
    {
        $this->run('UPDATE users SET last_login_at = ? WHERE tenant_id = ? AND id = ?', [$now, $tenantId, $userId]);
    }

    public function storeTwoFactorSecret(string $tenantId, string $userId, string $encryptedSecret, string $now): void
    {
        $this->run(
            'UPDATE users SET two_factor_secret = ?, two_factor_confirmed_at = NULL, two_factor_last_step = 0, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$encryptedSecret, $now, $tenantId, $userId],
        );
    }

    public function confirmTwoFactor(string $tenantId, string $userId, int $step, string $now): void
    {
        $this->run(
            'UPDATE users SET two_factor_confirmed_at = ?, two_factor_last_step = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$now, $step, $now, $tenantId, $userId],
        );
    }

    /** Mémorise le pas TOTP consommé ; faux si ce pas (ou un plus récent) a déjà servi : un code ne sert qu'une fois. */
    public function consumeTwoFactorStep(string $tenantId, string $userId, int $step): bool
    {
        return $this->run(
            'UPDATE users SET two_factor_last_step = ? WHERE tenant_id = ? AND id = ? AND two_factor_last_step < ?',
            [$step, $tenantId, $userId, $step],
        ) === 1;
    }

    /** @param array<string, mixed> $row */
    private static function map(array $row): User
    {
        return new User(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['person_id'],
            self::nullableString($row['phone']),
            self::nullableString($row['email']),
            self::nullableString($row['password_hash']),
            (string) $row['locale'],
            (string) $row['status'],
            self::nullableString($row['two_factor_secret']),
            self::nullableString($row['two_factor_confirmed_at']),
            (int) $row['two_factor_last_step'],
            (int) ($row['must_change_password'] ?? 0) === 1,
        );
    }
}
