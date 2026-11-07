<?php

declare(strict_types=1);

namespace Scholaris\Core\Idempotency;

use PDOException;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Mémorise les réponses des opérations sensibles (paiements, encaissements, documents officiels) :
 * un rejeu avec la même clé et la même requête renvoie la même réponse, sans refaire l'opération.
 */
final class IdempotencyStore
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * Réserve la clé. Retourne true si la réservation réussit (première fois), false si la clé existe déjà.
     */
    public function reserve(string $tenantId, string $key, string $route, string $requestHash, int $ttlSeconds): bool
    {
        $pdo = $this->connections->pdo();
        $pdo->prepare('DELETE FROM idempotency_keys WHERE expires_at < ?')->execute([time()]);

        try {
            $pdo->prepare(
                'INSERT INTO idempotency_keys (tenant_id, idem_key, route, request_hash, status_code, response_body, expires_at)
                 VALUES (?, ?, ?, ?, NULL, NULL, ?)',
            )->execute([$tenantId, $key, $route, $requestHash, time() + $ttlSeconds]);

            return true;
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) {
                return false;
            }
            throw $e;
        }
    }

    /** @return array{route: string, request_hash: string, status_code: int|null, response_body: string|null}|null */
    public function find(string $tenantId, string $key): ?array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT route, request_hash, status_code, response_body FROM idempotency_keys WHERE tenant_id = ? AND idem_key = ?',
        );
        $statement->execute([$tenantId, $key]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }
        $row['status_code'] = $row['status_code'] === null ? null : (int) $row['status_code'];

        return $row;
    }

    public function complete(string $tenantId, string $key, int $statusCode, string $body): void
    {
        $this->connections->pdo()->prepare(
            'UPDATE idempotency_keys SET status_code = ?, response_body = ? WHERE tenant_id = ? AND idem_key = ?',
        )->execute([$statusCode, $body, $tenantId, $key]);
    }

    public function release(string $tenantId, string $key): void
    {
        $this->connections->pdo()->prepare('DELETE FROM idempotency_keys WHERE tenant_id = ? AND idem_key = ?')
            ->execute([$tenantId, $key]);
    }

    private function isDuplicate(PDOException $e): bool
    {
        $code = (string) $e->getCode();

        return $code === '23000' || $code === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
