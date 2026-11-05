<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Throwable;

/** Requêtes propres à la messagerie interne (participants, lecture, signalements), toujours cloisonnées par client. */
final class ChatRepository
{
    /** Expression SQL de la dernière activité d'une conversation (alias c). */
    public const ACTIVITY = 'COALESCE(c.last_message_at, c.created_at)';

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
     * Conversations d'une personne, de la dernière activité (dernier message) à la plus ancienne, avec le nombre de
     * messages non lus. Curseur : « activité|identifiant » de la dernière ligne servie.
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
        $after = self::activityCursor($query->cursor);
        if ($after !== null) {
            $clauses[] = '(' . self::ACTIVITY . ' < ? OR (' . self::ACTIVITY . ' = ? AND c.id < ?))';
            array_push($params, $after[0], $after[0], $after[1]);
        }

        $statement = $this->connections->pdo()->prepare(sprintf(
            'SELECT c.*, ' . self::ACTIVITY . ' AS activity_at, (SELECT COUNT(*) FROM conversation_messages m WHERE m.tenant_id = c.tenant_id AND m.conversation_id = c.id '
            . "AND m.sender_id <> ? AND m.moderation_status = 'visible' AND (p.last_read_message_id IS NULL OR m.id > p.last_read_message_id)) AS unread_count "
            . 'FROM conversations c JOIN conversation_participants p ON p.tenant_id = c.tenant_id AND p.conversation_id = c.id '
            . 'WHERE %s ORDER BY activity_at DESC, c.id DESC LIMIT %d',
            implode(' AND ', $clauses),
            $query->perPage + 1,
        ));
        $statement->execute($params);

        return self::paginate(array_values($statement->fetchAll(PDO::FETCH_ASSOC)), $query->perPage);
    }

    /** Messages non lus d'une personne dans ses conversations ouvertes de l'établissement (compteur de la barre du haut). */
    public function unreadTotal(string $tenantId, string $establishmentId, string $personId): int
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT COUNT(*) FROM conversation_messages m '
            . 'JOIN conversation_participants p ON p.tenant_id = m.tenant_id AND p.conversation_id = m.conversation_id '
            . 'JOIN conversations c ON c.tenant_id = m.tenant_id AND c.id = m.conversation_id '
            . "WHERE m.tenant_id = ? AND c.establishment_id = ? AND p.person_id = ? AND c.status = 'open' AND m.sender_id <> ? "
            . "AND m.moderation_status = 'visible' AND (p.last_read_message_id IS NULL OR m.id > p.last_read_message_id)",
        );
        $statement->execute([$tenantId, $establishmentId, $personId, $personId]);

        return (int) $statement->fetchColumn();
    }

    /** Vrai si un e-mail ou un SMS a déjà signalé cette conversation à cette personne depuis `$sinceIso`. */
    public function notifiedSince(string $tenantId, string $conversationId, string $personId, string $channel, string $sinceIso): bool
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT last_sent_at FROM conversation_notification_marks WHERE tenant_id = ? AND conversation_id = ? AND person_id = ? AND channel = ? LIMIT 1',
        );
        $statement->execute([$tenantId, $conversationId, $personId, $channel]);
        $last = $statement->fetchColumn();

        return is_string($last) && strtotime($last) >= strtotime($sinceIso);
    }

    public function markNotified(string $tenantId, string $establishmentId, string $conversationId, string $personId, string $channel, string $at): void
    {
        $pdo = $this->connections->pdo();
        $update = $pdo->prepare('UPDATE conversation_notification_marks SET last_sent_at = ? WHERE tenant_id = ? AND conversation_id = ? AND person_id = ? AND channel = ?');
        $update->execute([$at, $tenantId, $conversationId, $personId, $channel]);
        if ($update->rowCount() === 0) {
            $pdo->prepare(
                'INSERT INTO conversation_notification_marks (id, tenant_id, establishment_id, conversation_id, person_id, channel, last_sent_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            )->execute([(string) new Ulid(), $tenantId, $establishmentId, $conversationId, $personId, $channel, $at]);
        }
    }

    /** @return array{0: string, 1: string}|null activité et identifiant décodés du curseur */
    public static function activityCursor(?string $cursor): ?array
    {
        $decoded = ListQuery::decodeCursor($cursor);
        if ($decoded === null || !str_contains($decoded, '|')) {
            return null;
        }
        [$at, $id] = explode('|', $decoded, 2);

        return [$at, $id];
    }

    /**
     * @param list<array<string, mixed>> $rows lignes portant activity_at (une de plus que la page)
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public static function paginate(array $rows, int $perPage): array
    {
        $next = null;
        if (count($rows) > $perPage) {
            $rows = array_slice($rows, 0, $perPage);
            $last = $rows[count($rows) - 1];
            $next = ListQuery::encodeCursor((string) $last['activity_at'] . '|' . (string) $last['id']);
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
