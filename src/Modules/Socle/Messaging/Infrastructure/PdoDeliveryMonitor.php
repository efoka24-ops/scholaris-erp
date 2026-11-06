<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Messaging\Application\MessagingClock;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryMonitor;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/** Suivi des envois pour la plateforme. Voir le contrat. Valeurs toujours liées ; aucun texte de message n'est lu. */
final class PdoDeliveryMonitor implements DeliveryMonitor
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly PersonDirectory $people,
        private readonly MessagingClock $clock,
    ) {
    }

    public function queue(): array
    {
        $row = $this->one(
            "SELECT SUM(CASE WHEN status IN ('pending', 'queued') THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,"
            . " MIN(CASE WHEN status IN ('pending', 'queued') THEN created_at END) AS oldest FROM message_deliveries",
            [],
        );

        return ['pending' => (int) ($row['pending'] ?? 0), 'failed' => (int) ($row['failed'] ?? 0), 'oldest_pending_at' => isset($row['oldest']) ? (string) $row['oldest'] : null];
    }

    public function countsSince(string $since): array
    {
        $statement = $this->connections->pdo()->prepare('SELECT channel, status, COUNT(*) AS total FROM message_deliveries WHERE created_at >= ? GROUP BY channel, status');
        $statement->execute([$since]);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['channel']][(string) $row['status']] = (int) $row['total'];
        }

        return $out;
    }

    public function journal(array $filters, ?string $beforeId, int $limit): array
    {
        $where = ['1 = 1'];
        $params = [];
        foreach (['channel' => 'd.channel', 'tenant_id' => 'd.tenant_id', 'status' => 'd.status', 'recipient_id' => 'd.recipient_id'] as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where[] = $column . ' = ?';
                $params[] = $filters[$key];
            }
        }
        if (isset($filters['from']) && preg_match(self::DATE, $filters['from']) === 1) {
            $where[] = 'd.created_at >= ?';
            $params[] = $filters['from'];
        }
        if (isset($filters['to']) && preg_match(self::DATE, $filters['to']) === 1) {
            $where[] = 'd.created_at <= ?';
            $params[] = $filters['to'] . '~';
        }
        if ($beforeId !== null) {
            $where[] = 'd.id < ?';
            $params[] = $beforeId;
        }
        $statement = $this->connections->pdo()->prepare(sprintf(
            'SELECT d.id, d.tenant_id, d.channel, d.recipient_id, d.status, d.reason, d.provider_reference, d.attempts, d.created_at, d.sent_at, d.delivered_at, m.template_key'
            . ' FROM message_deliveries d LEFT JOIN messages m ON m.tenant_id = d.tenant_id AND m.id = d.message_id WHERE %s ORDER BY d.id DESC LIMIT %d',
            implode(' AND ', $where),
            max(1, min($limit, 500)),
        ));
        $statement->execute($params);
        $rows = array_values($statement->fetchAll(PDO::FETCH_ASSOC));

        $byTenant = [];
        foreach ($rows as $row) {
            $byTenant[(string) $row['tenant_id']][] = (string) $row['recipient_id'];
        }
        $people = [];
        foreach ($byTenant as $tenantId => $ids) {
            foreach ($this->people->findMany((string) $tenantId, array_values(array_unique($ids))) as $person) {
                $people[$tenantId . '|' . $person->id] = $person;
            }
        }

        return array_map(function (array $row) use ($people): array {
            $person = $people[$row['tenant_id'] . '|' . $row['recipient_id']] ?? null;
            $address = $person === null ? null : ($row['channel'] === 'email' ? $person->email : ($row['channel'] === 'sms' ? $person->phone : null));

            return [
                'id' => (string) $row['id'],
                'tenant_id' => (string) $row['tenant_id'],
                'channel' => (string) $row['channel'],
                'recipient' => self::mask($address),
                'template_key' => $row['template_key'] === null ? null : (string) $row['template_key'],
                'status' => (string) $row['status'],
                'reason' => $row['reason'] === null ? null : (string) $row['reason'],
                'provider_reference' => $row['provider_reference'] === null ? null : (string) $row['provider_reference'],
                'attempts' => (int) $row['attempts'],
                'created_at' => (string) $row['created_at'],
                'sent_at' => $row['sent_at'] === null ? null : (string) $row['sent_at'],
                'delivered_at' => $row['delivered_at'] === null ? null : (string) $row['delivered_at'],
            ];
        }, $rows);
    }

    public function retry(string $tenantId, string $deliveryId): bool
    {
        $now = $this->clock->iso();
        $statement = $this->connections->pdo()->prepare(
            "UPDATE message_deliveries SET status = 'queued', reason = 'retry', not_before = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND status = 'failed'",
        );
        $statement->execute([$now, $now, $tenantId, $deliveryId]);

        return $statement->rowCount() === 1;
    }

    public function internalCounts(): array
    {
        $out = [];
        foreach ($this->rows('SELECT tenant_id, COUNT(*) AS total FROM conversations GROUP BY tenant_id') as $row) {
            $out[(string) $row['tenant_id']] = ['tenant_id' => (string) $row['tenant_id'], 'conversations' => (int) $row['total'], 'messages' => 0, 'hidden' => 0];
        }
        $sql = "SELECT tenant_id, COUNT(*) AS total, SUM(CASE WHEN moderation_status = 'hidden' THEN 1 ELSE 0 END) AS hidden FROM conversation_messages GROUP BY tenant_id";
        foreach ($this->rows($sql) as $row) {
            $tenant = (string) $row['tenant_id'];
            $out[$tenant] ??= ['tenant_id' => $tenant, 'conversations' => 0, 'messages' => 0, 'hidden' => 0];
            $out[$tenant]['messages'] = (int) $row['total'];
            $out[$tenant]['hidden'] = (int) $row['hidden'];
        }
        ksort($out);

        return array_values($out);
    }

    /** Téléphone « 6•• •• •• 12 », e-mail « p•••@domaine », rien : « — ». */
    public static function mask(?string $address): string
    {
        if ($address === null || trim($address) === '') {
            return '—';
        }
        if (str_contains($address, '@')) {
            [$local, $domain] = explode('@', $address, 2);

            return mb_substr($local, 0, 1) . '•••@' . $domain;
        }
        $digits = (string) preg_replace('/\D/', '', $address);
        if (str_starts_with($digits, '237') && strlen($digits) === 12) {
            $digits = substr($digits, 3);
        }
        if (strlen($digits) < 4) {
            return '•••';
        }

        return substr($digits, 0, 1) . '•• •• •• ' . substr($digits, -2);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute();

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
