<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Application;

use Scholaris\Modules\Apps\Higher\Vacations\Infrastructure\VacationStore;
use Scholaris\Modules\Socle\Staff\Contracts\PayableHour;
use Scholaris\Modules\Socle\Staff\Contracts\PayableStatement;

/**
 * Retenue du solde (RG-ENS-5 et toute condition d'état déclarée au socle) : l'état est présenté aux conditions de payabilité avec ses
 * lignes payables ; les motifs sont conservés sur l'état (`hold_reasons`) et bloquent l'approbation et le paiement.
 */
final class HoldEvaluator
{
    public function __construct(private readonly VacationStore $store, private readonly PayabilityGate $gate)
    {
    }

    /**
     * Réévalue et enregistre les motifs de retenue.
     *
     * @param array<string, mixed> $statement
     * @return list<string>
     */
    public function refresh(array $statement, string $actorId): array
    {
        if ($statement === []) {
            return [];
        }
        $tenantId = (string) $statement['tenant_id'];
        $establishmentId = (string) $statement['establishment_id'];
        $hours = [];
        foreach ($this->store->select(StatementService::LINES, $tenantId, $establishmentId, ['statement_id' => (string) $statement['id'], 'payable' => 1], 'held_on, id', 5000) as $line) {
            $hours[] = new PayableHour(
                (string) $line['session_log_id'],
                $tenantId,
                $establishmentId,
                (string) ($statement['staff_id'] ?? ''),
                (string) $statement['person_id'],
                (string) ($statement['staff_kind'] ?? 'vacataire'),
                (string) $line['held_on'],
                (int) $line['minutes'],
                (string) $line['session_kind'],
                null,
                (string) $line['element_id'],
                (string) $line['group_id'],
                $line['level_label'] === null ? null : (string) $line['level_label'],
                null,
                null,
                null,
                null,
                'validated',
            );
        }
        $reasons = $hours === [] ? [] : $this->gate->statementRefusals(new PayableStatement(
            (string) $statement['id'],
            $tenantId,
            $establishmentId,
            (string) ($statement['staff_id'] ?? ''),
            (string) $statement['person_id'],
            (string) $statement['period_month'],
            '',
            array_sum(array_map(static fn (PayableHour $h): int => $h->minutes, $hours)),
            (int) $statement['hetd_milli'],
            (int) $statement['gross_amount'],
            $hours,
        ), $actorId);
        $this->store->update(StatementService::TABLE, $tenantId, $establishmentId, ['hold_reasons' => $reasons === [] ? null : json_encode($reasons, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['id' => (string) $statement['id']]);

        return $reasons;
    }
}
