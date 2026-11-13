<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;

/** Compteur de SMS par établissement et par mois (AAAA-MM), base de la facturation. */
final class SmsUsageRepository
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function increment(string $tenantId, string $establishmentId, string $month, string $now): void
    {
        $pdo = $this->connections->pdo();
        $update = $pdo->prepare(
            'UPDATE sms_usage SET sms_count = sms_count + 1, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND usage_month = ?',
        );
        $update->execute([$now, $tenantId, $establishmentId, $month]);
        if ($update->rowCount() > 0) {
            return;
        }
        try {
            $pdo->prepare('INSERT INTO sms_usage (tenant_id, establishment_id, usage_month, sms_count, updated_at) VALUES (?, ?, ?, 1, ?)')
                ->execute([$tenantId, $establishmentId, $month, $now]);
        } catch (PDOException) {
            $update->execute([$now, $tenantId, $establishmentId, $month]);
        }
    }

    public function count(string $tenantId, string $establishmentId, string $month): int
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT sms_count FROM sms_usage WHERE tenant_id = ? AND establishment_id = ? AND usage_month = ?',
        );
        $statement->execute([$tenantId, $establishmentId, $month]);
        $value = $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    /** @return list<array<string, mixed>> mois les plus récents d'abord */
    public function list(string $tenantId, string $establishmentId, ?string $month = null): array
    {
        $sql = 'SELECT usage_month AS month, sms_count AS sms_sent, updated_at FROM sms_usage WHERE tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        if ($month !== null) {
            $sql .= ' AND usage_month = ?';
            $params[] = $month;
        }
        $statement = $this->connections->pdo()->prepare($sql . ' ORDER BY usage_month DESC LIMIT 120');
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
