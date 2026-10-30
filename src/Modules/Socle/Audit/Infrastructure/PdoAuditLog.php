<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Audit\Contracts\AuditVerification;

/**
 * Journal chaîné : hash = SHA-256(prev_hash | JSON canonique de l'entrée). La clé (tenant_id, seq) interdit
 * deux entrées de même rang : en cas de concurrence, la perdante recalcule son rang et réessaie.
 */
final class PdoAuditLog implements AuditLog
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    private const ATTEMPTS = 8;

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly string $timezone = 'Africa/Douala',
    ) {
    }

    public function append(
        string $tenantId,
        ?string $actorId,
        string $action,
        string $objectType,
        string $objectId,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
        ?string $device = null,
    ): int {
        $pdo = $this->connections->pdo();

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $last = $pdo->prepare('SELECT seq, hash FROM audit_log WHERE tenant_id = ? ORDER BY seq DESC LIMIT 1');
            $last->execute([$tenantId]);
            $row = $last->fetch();

            $seq = $row === false ? 1 : (int) $row['seq'] + 1;
            $prevHash = $row === false ? self::GENESIS : (string) $row['hash'];
            $occurredAt = (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format(DATE_ATOM);

            $entry = [
                'seq' => $seq,
                'actor_id' => $actorId,
                'action' => $action,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'before' => $before,
                'after' => $after,
                'ip' => $ip,
                'device' => $device,
                'occurred_at' => $occurredAt,
            ];
            $hash = self::hash($prevHash, $entry);

            try {
                $pdo->prepare(
                    'INSERT INTO audit_log (tenant_id, seq, actor_id, action, object_type, object_id, before_json, after_json, ip, device, occurred_at, prev_hash, hash)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                )->execute([
                    $tenantId, $seq, $actorId, $action, $objectType, $objectId,
                    $before === null ? null : self::json($before),
                    $after === null ? null : self::json($after),
                    $ip, $device, $occurredAt, $prevHash, $hash,
                ]);

                return $seq;
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), 'UNIQUE') && !in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Journal d\'audit : trop de collisions de rang, écriture abandonnée.');
    }

    public function verify(string $tenantId): AuditVerification
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM audit_log WHERE tenant_id = ? ORDER BY seq ASC');
        $statement->execute([$tenantId]);

        $expectedPrev = self::GENESIS;
        $expectedSeq = 1;
        $checked = 0;

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $entry = [
                'seq' => (int) $row['seq'],
                'actor_id' => $row['actor_id'],
                'action' => $row['action'],
                'object_type' => $row['object_type'],
                'object_id' => $row['object_id'],
                'before' => $row['before_json'] === null ? null : json_decode((string) $row['before_json'], true),
                'after' => $row['after_json'] === null ? null : json_decode((string) $row['after_json'], true),
                'ip' => $row['ip'],
                'device' => $row['device'],
                'occurred_at' => $row['occurred_at'],
            ];

            if ((int) $row['seq'] !== $expectedSeq
                || $row['prev_hash'] !== $expectedPrev
                || $row['hash'] !== self::hash($expectedPrev, $entry)) {
                return new AuditVerification(false, $checked, (int) $row['seq']);
            }

            $expectedPrev = (string) $row['hash'];
            $expectedSeq++;
            $checked++;
        }

        return new AuditVerification(true, $checked);
    }

    /** @param array<string, mixed> $entry */
    private static function hash(string $prevHash, array $entry): string
    {
        return hash('sha256', $prevHash . '|' . self::json($entry));
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
