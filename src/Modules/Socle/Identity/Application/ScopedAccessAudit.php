<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use InvalidArgumentException;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\NamedAccessJournal;

/**
 * PP3 (T607) : implémentation du journal nominatif. Chaque consultation est écrite dans `identity_named_access_journal`
 * (lue par le directeur de l'établissement) et dans le journal Audit chaîné, append-only.
 */
final class ScopedAccessAudit implements NamedAccessJournal
{
    public const ACTION = 'identity.named_record.accessed';
    private const PURPOSE_MAX = 500;

    public function __construct(
        private readonly AuditLog $audit,
        private readonly ConnectionFactory $connections,
        private readonly Moment $moment,
    ) {
    }

    public function record(
        string $tenantId,
        string $establishmentId,
        string $actorId,
        string $recordType,
        string $recordId,
        string $purpose,
        ?string $ip = null,
        ?string $device = null,
    ): void {
        $purpose = trim($purpose);
        if ($purpose === '' || mb_strlen($purpose) > self::PURPOSE_MAX) {
            throw new InvalidArgumentException('La finalité de la consultation nominative est obligatoire (500 caractères au plus).');
        }
        if (trim($establishmentId) === '' || trim($recordType) === '' || trim($recordId) === '') {
            throw new InvalidArgumentException('Établissement, type et identifiant de la donnée consultée sont obligatoires.');
        }
        $this->connections->pdo()->prepare(
            'INSERT INTO identity_named_access_journal (id, tenant_id, establishment_id, actor_id, record_type, record_id, purpose, ip, device, accessed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([(string) new Ulid(),$tenantId, $establishmentId, $actorId, $recordType, $recordId, $purpose, $ip, $device, $this->moment->iso()]);
        $this->audit->append($tenantId, $actorId, self::ACTION, $recordType, $recordId, null, ['establishment_id' => $establishmentId, 'purpose' => $purpose], $ip, $device);
    }

    /**
     * Consultations nominatives d'un établissement, des plus récentes aux plus anciennes (curseur : identifiant ULID).
     *
     * @return list<array{id: string, actor_id: string, record_type: string, record_id: string, purpose: string, accessed_at: string}>
     */
    public function listFor(string $tenantId, string $establishmentId, ?string $before, int $limit): array
    {
        $sql = 'SELECT id, actor_id, record_type, record_id, purpose, accessed_at FROM identity_named_access_journal '
            . 'WHERE tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        if ($before !== null && $before !== '') {
            $sql .= ' AND id < ?';
            $params[] = $before;
        }
        $statement = $this->connections->pdo()->prepare($sql . ' ORDER BY id DESC LIMIT ' . max(1, $limit));
        $statement->execute($params);

        return array_values(array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'actor_id' => (string) $row['actor_id'],
            'record_type' => (string) $row['record_type'],
            'record_id' => (string) $row['record_id'],
            'purpose' => (string) $row['purpose'],
            'accessed_at' => (string) $row['accessed_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC)));
    }
}
