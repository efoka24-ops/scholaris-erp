<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Support\Ulid;

/** Codes de secours de la 2FA : hachés, à usage unique. */
final class PdoBackupCodeRepository extends PdoRepository
{
    /** @param list<string> $hashes */
    public function replaceAll(string $tenantId, string $userId, array $hashes, string $now): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $this->run('DELETE FROM backup_codes WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]);
            foreach ($hashes as $hash) {
                $this->run(
                    'INSERT INTO backup_codes (id, tenant_id, user_id, code_hash, created_at) VALUES (?, ?, ?, ?, ?)',
                    [(string) new Ulid(), $tenantId, $userId, $hash, $now],
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    /** Consomme le code s'il est encore valable ; l'UPDATE conditionnel garantit l'usage unique même en concurrence. */
    public function consume(string $tenantId, string $userId, string $hash, string $now): bool
    {
        return $this->run(
            'UPDATE backup_codes SET used_at = ? WHERE tenant_id = ? AND user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$now, $tenantId, $userId, $hash],
        ) === 1;
    }

    public function countUnused(string $tenantId, string $userId): int
    {
        $row = $this->one('SELECT COUNT(*) AS n FROM backup_codes WHERE tenant_id = ? AND user_id = ? AND used_at IS NULL', [$tenantId, $userId]);

        return $row === null ? 0 : (int) $row['n'];
    }
}
