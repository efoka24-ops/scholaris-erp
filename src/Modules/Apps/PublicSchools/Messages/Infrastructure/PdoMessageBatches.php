<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Messages\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/** Journal des envois (pub_message_batches, pub_message_batch_classes) et des annonces territoriales (pub_territory_announcements). */
final class PdoMessageBatches
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByClientUuid(string $tenantId, string $establishmentId, string $clientUuid): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_message_batches WHERE tenant_id = ? AND establishment_id = ? AND client_uuid = ?');
        $query->execute([$tenantId, $establishmentId, $clientUuid]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, scalar> $variables
     * @param list<string> $classIds
     * @return array<string, mixed>
     */
    public function insert(string $tenantId, string $establishmentId, string $audience, ?string $target, string $templateKey, array $variables, array $classIds, int $recipients, string $sentBy, ?string $clientUuid, string $at): array
    {
        $pdo = $this->connections->pdo();
        $id = (string) new Ulid();
        $pdo->prepare('INSERT INTO pub_message_batches (id, tenant_id, establishment_id, audience, target, template_key, variables, classes_count, recipients_count, sent_by, client_uuid, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $establishmentId, $audience, $target, $templateKey, json_encode($variables, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT), count($classIds), $recipients, $sentBy, $clientUuid, $at]);
        $link = $pdo->prepare('INSERT INTO pub_message_batch_classes (tenant_id, batch_id, establishment_id, structure_id) VALUES (?, ?, ?, ?)');
        foreach ($classIds as $classId) {
            $link->execute([$tenantId, $id, $establishmentId, $classId]);
        }

        return ['id' => $id, 'audience' => $audience, 'target' => $target, 'template_key' => $templateKey, 'variables' => $variables, 'classes_count' => count($classIds), 'recipients_count' => $recipients, 'sent_by' => $sentBy, 'created_at' => $at];
    }

    /** @return list<array<string, mixed>> envois de l'école après le curseur */
    public function page(string $tenantId, string $establishmentId, ?string $afterId, int $limit): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_message_batches WHERE tenant_id = ? AND establishment_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit));
        $query->execute([$tenantId, $establishmentId, $afterId ?? '']);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> derniers envois qui ont touché la classe depuis le jour donné, du plus récent au plus ancien */
    public function recentForClass(string $tenantId, string $establishmentId, string $structureId, string $sinceIso, int $limit): array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT b.* FROM pub_message_batches b JOIN pub_message_batch_classes c ON c.tenant_id = b.tenant_id AND c.batch_id = b.id '
            . 'WHERE b.tenant_id = ? AND b.establishment_id = ? AND c.structure_id = ? AND b.created_at >= ? ORDER BY b.id DESC LIMIT ' . max(1, $limit),
        );
        $query->execute([$tenantId, $establishmentId, $structureId, $sinceIso]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insertAnnouncement(string $tenantId, string $territoryId, string $title, int $establishments, string $actorId, string $at): string
    {
        $id = (string) new Ulid();
        $this->connections->pdo()->prepare('INSERT INTO pub_territory_announcements (id, tenant_id, territory_id, title, establishments_count, announced_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $territoryId, $title, $establishments, $actorId, $at]);

        return $id;
    }

    /** @return list<array<string, mixed>> annonces d'un territoire après le curseur */
    public function announcements(string $tenantId, string $territoryId, ?string $afterId, int $limit): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_territory_announcements WHERE tenant_id = ? AND territory_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit));
        $query->execute([$tenantId, $territoryId, $afterId ?? '']);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }
}
