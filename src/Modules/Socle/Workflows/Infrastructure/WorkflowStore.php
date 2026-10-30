<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Surcharges des circuits et leur journal. Chaque requête porte le niveau, le client et l'établissement : une surcharge d'un
 * autre client ou d'un autre établissement n'est jamais lue. Valeurs toujours liées.
 */
final class WorkflowStore
{
    public const PLATFORM = 'platform';
    public const CLIENT = 'client';
    public const ESTABLISHMENT = 'establishment';

    /** Identifiant de client des réglages posés par la plateforme pour tous les clients. */
    public const PLATFORM_TENANT = 'platform';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
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
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Surcharges applicables, du niveau le plus haut au plus bas. Sans établissement (vue d'un client), le niveau établissement
     * est absent.
     *
     * @return array<string, array<string, mixed>> niveau => ligne (seuls les niveaux réglés sont présents)
     */
    public function layers(string $tenantId, ?string $establishmentId, string $application, string $circuit): array
    {
        $sql = 'SELECT * FROM workflow_overrides WHERE app_key = ? AND circuit_key = ? AND ('
            . '(scope_level = ? AND tenant_id = ? AND establishment_id = ?)'
            . ' OR (scope_level = ? AND tenant_id = ? AND establishment_id = ?)';
        $args = [$application, $circuit, self::PLATFORM, self::PLATFORM_TENANT, '', self::CLIENT, $tenantId, ''];
        if ($establishmentId !== null) {
            $sql .= ' OR (scope_level = ? AND tenant_id = ? AND establishment_id = ?)';
            array_push($args, self::ESTABLISHMENT, $tenantId, $establishmentId);
        }
        $statement = $this->connections->pdo()->prepare($sql . ')');
        $statement->execute($args);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['scope_level']] = $row;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(string $level, string $tenantId, string $establishmentId, string $application, string $circuit): ?array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM workflow_overrides WHERE scope_level = ? AND tenant_id = ? AND establishment_id = ? AND app_key = ? AND circuit_key = ?',
        );
        $statement->execute([$level, $tenantId, $establishmentId, $application, $circuit]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $statement = $this->connections->pdo()->prepare(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
        );
        $statement->execute(array_values($row));
    }

    public function update(string $id, string $docJson, int $version, string $reason, string $actorId, string $now): void
    {
        $statement = $this->connections->pdo()->prepare(
            'UPDATE workflow_overrides SET doc_json = ?, version_no = ?, change_reason = ?, updated_by = ?, updated_at = ? WHERE id = ?',
        );
        $statement->execute([$docJson, $version, $reason, $actorId, $now, $id]);
    }

    public function delete(string $id): void
    {
        $this->connections->pdo()->prepare('DELETE FROM workflow_overrides WHERE id = ?')->execute([$id]);
    }

    /** @return int dernier numéro de version journalisé pour ce circuit à ce niveau (0 si aucun) */
    public function lastVersion(string $level, string $tenantId, string $establishmentId, string $application, string $circuit): int
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT MAX(version_no) FROM workflow_changes WHERE scope_level = ? AND tenant_id = ? AND establishment_id = ? AND app_key = ? AND circuit_key = ?',
        );
        $statement->execute([$level, $tenantId, $establishmentId, $application, $circuit]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> les plus récentes d'abord */
    public function history(string $level, string $tenantId, string $establishmentId, string $application, string $circuit, int $limit): array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM workflow_changes WHERE scope_level = ? AND tenant_id = ? AND establishment_id = ? AND app_key = ? AND circuit_key = ?'
            . ' ORDER BY version_no DESC LIMIT ' . max(1, min(200, $limit)),
        );
        $statement->execute([$level, $tenantId, $establishmentId, $application, $circuit]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
