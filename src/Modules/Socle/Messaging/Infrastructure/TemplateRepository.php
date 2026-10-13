<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Modèles de l'établissement et modèles de la plateforme (tenant_id = 'platform'). Les modèles de l'établissement
 * passent avant ceux de la plateforme. Un client ne voit jamais les modèles d'un autre client.
 */
final class TemplateRepository
{
    public const PLATFORM = 'platform';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @param array<string, string> $filters template_key, locale, channel
     * @return list<array<string, mixed>>
     */
    public function visible(string $tenantId, string $establishmentId, array $filters = [], int $limit = 200, ?string $afterId = null): array
    {
        $clauses = ['((tenant_id = ? AND establishment_id = ?) OR tenant_id = ?)'];
        $params = [$tenantId, $establishmentId, self::PLATFORM];
        foreach (['template_key', 'locale', 'channel'] as $column) {
            if (isset($filters[$column])) {
                $clauses[] = $column . ' = ?';
                $params[] = $filters[$column];
            }
        }
        if ($afterId !== null) {
            $clauses[] = 'id > ?';
            $params[] = $afterId;
        }
        $statement = $this->connections->pdo()->prepare(sprintf(
            'SELECT * FROM message_templates WHERE %s ORDER BY id LIMIT %d',
            implode(' AND ', $clauses),
            max(1, $limit),
        ));
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function findVisible(string $tenantId, string $establishmentId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM message_templates WHERE id = ? AND ((tenant_id = ? AND establishment_id = ?) OR tenant_id = ?) LIMIT 1',
        );
        $statement->execute([$id, $tenantId, $establishmentId, self::PLATFORM]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Modèle applicable : établissement d'abord, plateforme ensuite.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $tenantId, string $establishmentId, string $key, string $locale, string $channel): ?array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM message_templates WHERE template_key = ? AND locale = ? AND channel = ? '
            . 'AND ((tenant_id = ? AND establishment_id = ?) OR tenant_id = ?) '
            . 'ORDER BY CASE WHEN tenant_id = ? THEN 1 ELSE 0 END, id LIMIT 1',
        );
        $statement->execute([$key, $locale, $channel, $tenantId, $establishmentId, self::PLATFORM, self::PLATFORM]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<string> canaux pour lesquels un modèle existe sous cette clé */
    public function channelsOf(string $tenantId, string $establishmentId, string $key): array
    {
        $channels = [];
        foreach ($this->visible($tenantId, $establishmentId, ['template_key' => $key]) as $row) {
            $channels[(string) $row['channel']] = true;
        }

        return array_keys($channels);
    }

    public function exists(string $tenantId, string $establishmentId, string $key, string $locale, string $channel): bool
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT 1 FROM message_templates WHERE tenant_id = ? AND establishment_id = ? AND template_key = ? AND locale = ? AND channel = ? LIMIT 1',
        );
        $statement->execute([$tenantId, $establishmentId, $key, $locale, $channel]);

        return $statement->fetchColumn() !== false;
    }
}
