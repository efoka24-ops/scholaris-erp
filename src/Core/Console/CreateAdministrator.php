<?php

declare(strict_types=1);

namespace Scholaris\Core\Console;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;
use Throwable;

/**
 * Amorçage : crée un client (s'il n'existe pas), une personne, un compte actif et le rôle
 * super_admin au niveau du client. À lancer une fois, depuis la console, après `migrate`.
 *
 * Le mot de passe est généré (16 caractères, aléatoire) et affiché une seule fois : il n'est jamais
 * passé en argument (historique du terminal) ni stocké en clair. Le super administrateur est un rôle
 * sensible : il devra activer la double authentification à sa première connexion.
 *
 * Volontairement en SQL direct pour ne dépendre d'aucune classe de module : c'est un outil d'installation.
 */
final class CreateAdministrator
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly string $timezone = 'Africa/Douala',
    ) {
    }

    /** @return array{tenant_id: string, user_id: string, password: string, login: string} */
    public function create(string $tenantName, string $firstName, string $lastName, string $identifier): array
    {
        $tenantName = trim($tenantName);
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ($tenantName === '' || $firstName === '' || $lastName === '') {
            throw new InvalidArgumentException('Le nom du client, le prénom et le nom sont obligatoires.');
        }

        [$phone, $email] = self::parseIdentifier($identifier);
        $login = $phone ?? (string) $email;
        $password = self::generatePassword();
        $now = (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format(DATE_ATOM);
        $pdo = $this->connections->pdo();

        $pdo->beginTransaction();
        try {
            $tenantId = $this->tenantId($pdo, $tenantName, $now);
            $this->assertFree($pdo, $tenantId, $phone, $email);

            $personId = (string) new Ulid();
            $userId = (string) new Ulid();

            $pdo->prepare('INSERT INTO people (id, tenant_id, first_name, last_name, phone, email, locale, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$personId, $tenantId, $firstName, $lastName, $phone, $email, 'fr', $now]);

            $pdo->prepare(
                'INSERT INTO users (id, tenant_id, person_id, phone, email, password_hash, locale, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([$userId, $tenantId, $personId, $phone, $email, password_hash($password, PASSWORD_ARGON2ID), 'fr', 'active', $now, $now]);

            $pdo->prepare(
                'INSERT INTO role_assignments (id, tenant_id, person_id, role, scope_type, scope_id, establishment_id, starts_at, ends_at, delegated_by, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?, NULL, NULL, ?, ?)',
            )->execute([(string) new Ulid(), $tenantId, $personId, 'super_admin', 'tenant', $tenantId, $now, 'console', $now]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['tenant_id' => $tenantId, 'user_id' => $userId, 'password' => $password, 'login' => $login];
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function parseIdentifier(string $raw): array
    {
        $raw = trim($raw);
        $email = mb_strtolower($raw);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return [null, $email];
        }

        $clean = (string) preg_replace('/[\s().\-]/', '', $raw);
        if (str_starts_with($clean, '00')) {
            $clean = '+' . substr($clean, 2);
        }
        if (preg_match('/^[26]\d{8}$/', $clean) === 1) {
            $clean = '+237' . $clean;
        } elseif (preg_match('/^237[26]\d{8}$/', $clean) === 1) {
            $clean = '+' . $clean;
        }
        if (preg_match('/^\+\d{8,15}$/', $clean) !== 1) {
            throw new InvalidArgumentException('Identifiant invalide : indiquez une adresse e-mail ou un numéro de téléphone (ex. 699123456).');
        }

        return [$clean, null];
    }

    private function tenantId(PDO $pdo, string $name, string $now): string
    {
        $find = $pdo->prepare('SELECT id FROM tenants WHERE name = ?');
        $find->execute([$name]);
        $existing = $find->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) new Ulid();
        $pdo->prepare('INSERT INTO tenants (id, name, status, plan, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $name, 'active', 'standard', $now, $now]);

        return $id;
    }

    private function assertFree(PDO $pdo, string $tenantId, ?string $phone, ?string $email): void
    {
        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE tenant_id = ? AND (phone = ? OR email = ?)');
        $check->execute([$tenantId, $phone ?? '', $email ?? '']);
        if ((int) $check->fetchColumn() > 0) {
            throw new InvalidArgumentException('Ce téléphone ou cet e-mail est déjà utilisé dans ce client.');
        }
    }

    private static function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 16; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
