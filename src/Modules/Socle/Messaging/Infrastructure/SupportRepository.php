<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Lectures et écritures de la boîte du support, la seule exception transversale aux clients du module : CHAQUE requête
 * impose `kind = 'support'` (les conversations internes d'un établissement n'y apparaissent jamais) et le client de la
 * conversation trouvée est réutilisé pour toute requête suivante.
 */
final class SupportRepository
{
    public const KIND = 'support';
    public const PLATFORM_SIDE = 'platform';

    private const COLUMNS = 'c.id, c.tenant_id, c.establishment_id, c.subject, c.kind, c.created_by, c.status, c.support_status, c.support_assignee, '
        . 'c.platform_read_message_id, c.last_message_at, c.archived_at, c.created_at, c.updated_at';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @param array{status?: string, tenant_id?: string, assignee?: string} $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function page(array $filters, ?string $cursor, int $perPage): array
    {
        $clauses = ["c.kind = 'support'"];
        $params = [];
        if (isset($filters['status'])) {
            $clauses[] = 'c.support_status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['tenant_id'])) {
            $clauses[] = 'c.tenant_id = ?';
            $params[] = $filters['tenant_id'];
        }
        if (isset($filters['assignee'])) {
            if ($filters['assignee'] === 'none') {
                $clauses[] = 'c.support_assignee IS NULL';
            } else {
                $clauses[] = 'c.support_assignee = ?';
                $params[] = $filters['assignee'];
            }
        }
        $after = ChatRepository::activityCursor($cursor);
        if ($after !== null) {
            $clauses[] = '(' . ChatRepository::ACTIVITY . ' < ? OR (' . ChatRepository::ACTIVITY . ' = ? AND c.id < ?))';
            array_push($params, $after[0], $after[0], $after[1]);
        }

        $statement = $this->connections->pdo()->prepare(sprintf(
            'SELECT ' . self::COLUMNS . ', ' . ChatRepository::ACTIVITY . ' AS activity_at, '
            . '(SELECT COUNT(*) FROM conversation_messages m WHERE m.tenant_id = c.tenant_id AND m.conversation_id = c.id '
            . "AND (m.sender_side IS NULL OR m.sender_side <> 'platform') "
            . 'AND (c.platform_read_message_id IS NULL OR m.id > c.platform_read_message_id)) AS unread_count '
            . 'FROM conversations c WHERE %s ORDER BY activity_at DESC, c.id DESC LIMIT %d',
            implode(' AND ', $clauses),
            $perPage + 1,
        ));
        $statement->execute($params);

        return ChatRepository::paginate(array_values($statement->fetchAll(PDO::FETCH_ASSOC)), $perPage);
    }

    /** @return array<string, mixed>|null conversation de support, null si absente ou d'une autre nature */
    public function find(string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT ' . self::COLUMNS . " FROM conversations c WHERE c.id = ? AND c.kind = 'support' LIMIT 1");
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function messages(string $tenantId, string $conversationId, ?string $afterId, int $perPage): array
    {
        $sql = 'SELECT m.* FROM conversation_messages m JOIN conversations c ON c.tenant_id = m.tenant_id AND c.id = m.conversation_id '
            . "WHERE m.tenant_id = ? AND m.conversation_id = ? AND c.kind = 'support'";
        $params = [$tenantId, $conversationId];
        if ($afterId !== null) {
            $sql .= ' AND m.id < ?';
            $params[] = $afterId;
        }
        $statement = $this->connections->pdo()->prepare($sql . sprintf(' ORDER BY m.id DESC LIMIT %d', $perPage + 1));
        $statement->execute($params);
        $rows = array_values($statement->fetchAll(PDO::FETCH_ASSOC));
        $next = null;
        if (count($rows) > $perPage) {
            $rows = array_slice($rows, 0, $perPage);
            $next = (string) $rows[count($rows) - 1]['id'];
        }

        return [$rows, $next];
    }

    /** @param array<string, scalar|null> $values colonnes support_status, support_assignee, platform_read_message_id, last_message_at, updated_at */
    public function update(string $tenantId, string $id, array $values): void
    {
        $allowed = ['support_status', 'support_assignee', 'platform_read_message_id', 'last_message_at', 'updated_at'];
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException('Colonne non modifiable : ' . $column);
            }
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
        if ($sets === []) {
            return;
        }
        array_push($params, $tenantId, $id);
        $this->connections->pdo()->prepare('UPDATE conversations SET ' . implode(', ', $sets) . " WHERE tenant_id = ? AND id = ? AND kind = 'support'")->execute($params);
    }

    public function markRead(string $tenantId, string $id, string $messageId): void
    {
        $this->connections->pdo()->prepare(
            "UPDATE conversations SET platform_read_message_id = ? WHERE tenant_id = ? AND id = ? AND kind = 'support' "
            . 'AND (platform_read_message_id IS NULL OR platform_read_message_id < ?)',
        )->execute([$messageId, $tenantId, $id, $messageId]);
    }
}
