<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Infrastructure;

use Scholaris\Modules\Apps\Secondary\Fees\Contracts\FeeCompliance;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Comptes des demandes d'ajout au catalogue (`se_fee_requests`) en une requête groupée. */
final class PdoFeeCompliance implements FeeCompliance
{
    public function __construct(private readonly Store $store)
    {
    }

    public function requestCounts(string $tenantId, string $establishmentId, string $from, string $to): array
    {
        $statement = $this->store->pdo()->prepare(
            'SELECT origin, status, COUNT(*) AS total FROM se_fee_requests WHERE tenant_id = ? AND establishment_id = ? AND created_at >= ? AND created_at < ?'
            . ' GROUP BY origin, status',
        );
        $statement->execute([$tenantId, $establishmentId, substr($from, 0, 10), substr($to, 0, 10) . 'T99']);
        $out = ['blocked' => 0, 'pending' => 0, 'accepted' => 0, 'refused' => 0];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $total = (int) $row['total'];
            if ((string) $row['origin'] === 'guard') {
                $out['blocked'] += $total;
            }
            $status = (string) $row['status'];
            if (in_array($status, ['pending', 'accepted', 'refused'], true)) {
                $out[$status] += $total;
            }
        }

        return $out;
    }
}
