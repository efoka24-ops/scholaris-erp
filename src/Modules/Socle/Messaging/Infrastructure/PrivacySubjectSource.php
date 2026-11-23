<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Audit\Contracts\Pseudonym;
use Scholaris\Modules\Socle\Audit\Contracts\SubjectDataSource;

/**
 * Données de messagerie d'une personne concernée. L'effacement vide le contenu de ses messages et de ses envois
 * (le fil et les horodatages restent, pour que les conversations des autres participants demeurent cohérentes).
 */
final class PrivacySubjectSource implements SubjectDataSource
{
    public const ERASED_BODY = '[message supprimé]';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function name(): string
    {
        return 'messaging';
    }

    public function collect(string $tenantId, string $personId): array
    {
        return [
            'conversation_participants' => $this->rows('SELECT id, conversation_id, chat_role, created_at FROM conversation_participants WHERE tenant_id = ? AND person_id = ? ORDER BY id', [$tenantId, $personId]),
            'conversation_messages' => $this->rows('SELECT id, conversation_id, body, attachments, moderation_status, created_at FROM conversation_messages WHERE tenant_id = ? AND sender_id = ? ORDER BY id', [$tenantId, $personId]),
            'message_deliveries' => $this->rows('SELECT id, message_id, channel, status, sent_at, delivered_at, created_at FROM message_deliveries WHERE tenant_id = ? AND recipient_id = ? ORDER BY id', [$tenantId, $personId]),
            'conversation_reports' => $this->rows('SELECT id, conversation_id, message_id, reason, status, created_at FROM conversation_reports WHERE tenant_id = ? AND reported_by = ? ORDER BY id', [$tenantId, $personId]),
        ];
    }

    public function anonymize(string $tenantId, string $personId): int
    {
        $pseudonym = Pseudonym::of($tenantId, $personId);
        $touched = 0;
        $touched += $this->run('UPDATE conversation_messages SET body = ?, attachments = NULL WHERE tenant_id = ? AND sender_id = ?', [self::ERASED_BODY, $tenantId, $personId]);
        $touched += $this->run('UPDATE conversation_messages SET sender_id = ? WHERE tenant_id = ? AND sender_id = ?', [$pseudonym, $tenantId, $personId]);
        $touched += $this->run('UPDATE message_deliveries SET variables = NULL, provider_reference = NULL, recipient_id = ? WHERE tenant_id = ? AND recipient_id = ?', [$pseudonym, $tenantId, $personId]);
        $touched += $this->run('UPDATE conversation_participants SET person_id = ? WHERE tenant_id = ? AND person_id = ?', [$pseudonym, $tenantId, $personId]);
        $touched += $this->run('UPDATE conversation_reports SET reported_by = ?, reason = NULL WHERE tenant_id = ? AND reported_by = ?', [$pseudonym, $tenantId, $personId]);

        return $touched;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /** @param list<scalar|null> $params */
    private function run(string $sql, array $params): int
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }
}
