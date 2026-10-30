<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

/** Codes à usage unique (activation, réinitialisation, 2FA par SMS). Seule l'empreinte du code est stockée. */
final class PdoOneTimeCodeRepository extends PdoRepository
{
    /**
     * Crée un code et invalide les précédents du même usage.
     *
     * L'empreinte du code dépend de l'identifiant : l'appelant choisit l'identifiant (ULID) avant de calculer l'empreinte.
     */
    public function insert(string $id, string $tenantId, string $userId, string $purpose, string $channel, string $codeHash, string $expiresAt, string $now): void
    {
        $this->run(
            'UPDATE one_time_codes SET consumed_at = ? WHERE tenant_id = ? AND user_id = ? AND purpose = ? AND consumed_at IS NULL',
            [$now, $tenantId, $userId, $purpose],
        );
        $this->run(
            'INSERT INTO one_time_codes (id, tenant_id, user_id, purpose, channel, code_hash, expires_at, attempts, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
            [$id, $tenantId, $userId, $purpose, $channel, $codeHash, $expiresAt, $now],
        );
    }

    /**
     * Dernier code non consommé et non vérifié d'un utilisateur pour l'un des usages.
     *
     * @param list<string> $purposes
     * @return array{id: string, tenant_id: string, user_id: string, code_hash: string, expires_at: string, attempts: int}|null
     */
    public function latestOpen(string $tenantId, string $userId, array $purposes): ?array
    {
        $marks = implode(', ', array_fill(0, count($purposes), '?'));
        $row = $this->one(
            'SELECT id, tenant_id, user_id, code_hash, expires_at, attempts FROM one_time_codes
             WHERE tenant_id = ? AND user_id = ? AND purpose IN (' . $marks . ') AND consumed_at IS NULL AND verified_at IS NULL
             ORDER BY created_at DESC, id DESC LIMIT 1',
            [$tenantId, $userId, ...$purposes],
        );
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'user_id' => (string) $row['user_id'],
            'code_hash' => (string) $row['code_hash'],
            'expires_at' => (string) $row['expires_at'],
            'attempts' => (int) $row['attempts'],
        ];
    }

    /**
     * Invalide tous les codes encore ouverts d'un utilisateur pour les usages donnés.
     *
     * @param list<string> $purposes
     */
    public function consumeOpen(string $tenantId, string $userId, array $purposes, string $now): void
    {
        $marks = implode(', ', array_fill(0, count($purposes), '?'));
        $this->run(
            'UPDATE one_time_codes SET consumed_at = ? WHERE tenant_id = ? AND user_id = ? AND purpose IN (' . $marks . ') AND consumed_at IS NULL',
            [$now, $tenantId, $userId, ...$purposes],
        );
    }

    /**
     * Nombre de codes créés pour un utilisateur par un canal donné depuis l'instant indiqué, et le plus ancien d'entre eux.
     *
     * @return array{count: int, oldest: ?string}
     */
    public function issuedSince(string $tenantId, string $userId, string $channel, string $since): array
    {
        $row = $this->one(
            'SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM one_time_codes WHERE tenant_id = ? AND user_id = ? AND channel = ? AND created_at > ?',
            [$tenantId, $userId, $channel, $since],
        );

        return ['count' => $row === null ? 0 : (int) $row['n'], 'oldest' => $row === null || $row['oldest'] === null ? null : (string) $row['oldest']];
    }

    public function incrementAttempts(string $tenantId, string $id): void
    {
        $this->run('UPDATE one_time_codes SET attempts = attempts + 1 WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    public function consume(string $tenantId, string $id, string $now): void
    {
        $this->run('UPDATE one_time_codes SET consumed_at = ? WHERE tenant_id = ? AND id = ?', [$now, $tenantId, $id]);
    }

    /** Marque le code vérifié et dépose le ticket (haché) qui autorise la définition du mot de passe. */
    public function markVerified(string $tenantId, string $id, string $ticketHash, string $ticketExpiresAt, string $now): void
    {
        $this->run(
            'UPDATE one_time_codes SET verified_at = ?, ticket_hash = ?, ticket_expires_at = ? WHERE tenant_id = ? AND id = ?',
            [$now, $ticketHash, $ticketExpiresAt, $tenantId, $id],
        );
    }

    /**
     * Recherche à partir du ticket secret (aucun client connu à ce stade).
     *
     * @return array{id: string, tenant_id: string, user_id: string, purpose: string}|null
     */
    public function findByTicket(string $ticketHash, string $now): ?array
    {
        $row = $this->one(
            'SELECT id, tenant_id, user_id, purpose FROM one_time_codes
             WHERE ticket_hash = ? AND consumed_at IS NULL AND ticket_expires_at > ?',
            [$ticketHash, $now],
        );
        if ($row === null) {
            return null;
        }

        return ['id' => (string) $row['id'], 'tenant_id' => (string) $row['tenant_id'], 'user_id' => (string) $row['user_id'], 'purpose' => (string) $row['purpose']];
    }
}
