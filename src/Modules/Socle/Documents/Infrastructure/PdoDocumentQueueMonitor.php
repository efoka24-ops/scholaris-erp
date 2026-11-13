<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentQueueMonitor;

/** Comptes de la file `document_jobs`, tous clients confondus (voir le contrat). */
final class PdoDocumentQueueMonitor implements DocumentQueueMonitor
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function queue(string $since): array
    {
        $statement = $this->connections->pdo()->prepare(
            "SELECT SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running,"
            . " SUM(CASE WHEN status = 'failed' AND finished_at >= ? THEN 1 ELSE 0 END) AS failed,"
            . " MIN(CASE WHEN status = 'queued' THEN created_at END) AS oldest FROM document_jobs",
        );
        $statement->execute([$since]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'running' => (int) ($row['running'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'oldest_pending_at' => isset($row['oldest']) ? (string) $row['oldest'] : null,
        ];
    }
}
