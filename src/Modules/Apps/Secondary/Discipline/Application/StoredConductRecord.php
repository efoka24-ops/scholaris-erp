<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Application;

use Scholaris\Modules\Apps\Secondary\Councils\Contracts\ConductRules;
use Scholaris\Modules\Apps\Secondary\Discipline\Contracts\ConductRecord;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Sanctions du barème appliquées (se_conduct_proposals, statut applied), pour le contrat ConductRecord. */
final class StoredConductRecord implements ConductRecord
{
    public function __construct(private readonly Store $store)
    {
    }

    public function appliedFor(string $tenantId, string $establishmentId, string $personId, string $from, string $to): array
    {
        $rows = [];
        foreach ($this->store->select(ConductProposals::TABLE, $tenantId, $establishmentId, ['person_id' => $personId, 'status' => ConductRules::APPLIED], 'period_from, id') as $row) {
            if ((string) $row['period_from'] >= $from && (string) $row['period_to'] <= $to) {
                $rows[] = [
                    'code' => (string) $row['proposed_kind'], 'label' => (string) $row['proposed_label'], 'unjustified_hours' => (float) $row['unjustified_hours'],
                    'period_from' => (string) $row['period_from'], 'period_to' => (string) $row['period_to'], 'decided_at' => is_string($row['decided_at']) ? $row['decided_at'] : null,
                ];
            }
        }

        return $rows;
    }
}
