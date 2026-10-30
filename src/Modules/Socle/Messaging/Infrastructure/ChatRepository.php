<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\ListQuery;
use Throwable;

/** Requêtes propres à la messagerie interne (participants, lecture, signalements), toujours cloisonnées par client. */
final class ChatRepository
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @return list<array<string, mixed>> */
    public function participants(string $tenantId, string $conversationId): array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM conversation_participants WHERE tenant_id = ? AND conversation_id = ? ORDER BY id',
        );
        $statement->execute([$tenantId, $conversationId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function participant(string $tenantId, string $conversationId, string $personId): ?array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM conversation_participants WHERE tenant_id = ? AND conversation_id = ? AND person_id = ? LIMIT 1',
        );
        $statement->execute([$tenantId, $conversationId, $personId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Conversations d'une personne, de la plus récente à la plus ancienne, avec le nombre de messages non lus.
     *
     * @param array<string, string> $filters status, kind
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function pageForPerson(string $tenantId, string $establishmentId, string $personId, array $filters, ListQuery $query): array
    {
        $clauses = ['c.tenant_id = ?', 'c.establishment_id = ?', 'p.person_id = ?'];
        $params = [$personId, $tenantId, $establishmentId, $personId];
        foreach (['status', 'kind'] as $column) {
            if (isset($filters[$column])) {
                $clauses[] = 'c.' . $column . ' = ?';
                $params[] = $filters[$column];
            }
        }
        $after = ListQuery::decodeCursor($query->cursor);
        if ($after !== null) {
            $clauses[] = 'c.id < ?';
            $params[] = $after;
        }

        $statement = $this->connections->pdo()->prepare(sprintf(
            'SELECT c.*, (SELECT COUNT(*) FROM conversation_messages m WHERE m.tenant_id = c.tenant_id AND m.conversation_id = c.id '
            . "AND m.sender_id <> ? AND m.moderation_status = 'visible' AND (p.last_read_message_id IS NULL OR m.id > p.last_read_message_id)) AS unread_count "
            . 'FROM conversations c JOIN conversation_participants p ON p.tenant_id = c.tenant_id AND p.conversation_id = c.id '
            . 'WHERE %s ORDER BY c.id DESC LIMIT %d',
            implode(' AND ', $clauses),
            $query->perPage + 1,
        ));
        $statement->execute($params);
        $rows = array_values($statement->fetchAll(PDO::FETCH_ASSOC));

        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return [$rows, $next];
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        $pdo = $this->connections->pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    public function markRead(string $tenantId, string $conversationId, string $personId, string $messageId): void
    {
        $this->connections->pdo()->prepare(
            'UPDATE conversation_participants SET last_read_message_id = ? WHERE tenant_id = ? AND conversation_id = ? AND person_id = ? '
            . 'AND (last_read_message_id IS NULL OR last_read_message_id < ?)',
        )->execute([$messageId, $tenantId, $conversationId, $personId, $messageId]);
    }

    public function reportExists(string $tenantId, string $messageId, string $reporterPersonId): bool
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT 1 FROM conversation_reports WHERE tenant_id = ? AND message_id = ? AND reported_by = ? LIMIT 1',
        );
        $statement->execute([$tenantId, $messageId, $reporterPersonId]);

        return $statement->fetchColumn() !== false;
    }

    /** Clôt les signalements ouverts d'un message. @return int nombre de signalements clos */
    public function resolveOpenReports(string $tenantId, string $establishmentId, string $messageId, string $status, string $resolvedBy, string $at): int
    {
        $statement = $this->connections->pdo()->prepare(
            'UPDATE conversation_reports SET status = ?, resolved_by = ?, resolved_at = ? '
            . "WHERE tenant_id = ? AND establishment_id = ? AND message_id = ? AND status = 'open'",
        );
        $statement->execute([$status, $resolvedBy, $at, $tenantId, $establishmentId, $messageId]);

        return $statement->rowCount();
    }
}
