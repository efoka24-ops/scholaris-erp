<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;

/**
 * Contrôle de cohérence quotidien (SC-MAT-1) : « aucune remise sans autorisation valable ou code validé ». Une remise est conforme
 * si elle porte l'identifiant d'une autorisation qui existe pour CET enfant ET (valable ce jour-là OU marquée `stale_authorization`,
 * remise hors ligne enregistrée après coup avec alerte) ; un code utilisé doit lui aussi pointer une autorisation. Toute autre remise
 * est une anomalie.
 */
final class ConsistencyCheck
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly Store $store,
        private readonly AuthorizedPickupRegistry $registry,
    ) {
    }

    /** @return list<array{0: string, 1: string}> */
    public function establishments(): array
    {
        $rows = $this->connections->pdo()->query('SELECT DISTINCT tenant_id, establishment_id FROM nu_gate_events ORDER BY tenant_id, establishment_id');
        $out = [];
        foreach ($rows !== false ? $rows->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
            $out[] = [(string) $row['tenant_id'], (string) $row['establishment_id']];
        }

        return $out;
    }

    /** @return list<array{event_id: string, learner_id: string, problem: string}> */
    public function violations(string $tenantId, string $establishmentId, string $day): array
    {
        $found = [];
        foreach ($this->store->select(GateJournal::TABLE, $tenantId, $establishmentId, ['school_day' => $day, 'event_type' => GateJournal::HANDED_OVER], 'occurred_at, id', 5000) as $event) {
            $problem = null;
            $pickupId = $event['pickup_id'] !== null ? (string) $event['pickup_id'] : null;
            $pickup = $pickupId !== null ? $this->registry->find($tenantId, $establishmentId, $pickupId) : null;
            if ($pickup === null || $pickup->learnerId !== (string) $event['learner_id']) {
                $problem = 'remise sans autorisation connue pour cet enfant';
            } elseif ((int) $event['stale_authorization'] !== 1 && !$pickup->isValidOn($day) && $event['code_id'] === null && !$this->wasValidAtTheTime($pickup, $day)) {
                $problem = 'remise avec une autorisation non valable ce jour-là';
            }
            if ($problem !== null) {
                $found[] = ['event_id' => (string) $event['id'], 'learner_id' => (string) $event['learner_id'], 'problem' => $problem];
            }
        }

        return $found;
    }

    /**
     * Une autorisation permanente révoquée APRÈS une remise légitime reste conforme : on ne juge que ce que le registre sait dire. Une
     * autorisation « du jour » d'un autre jour ne l'est jamais.
     */
    private function wasValidAtTheTime(\Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo $pickup, string $day): bool
    {
        return $pickup->grantType === $pickup::PERMANENT || $pickup->validOn === $day;
    }
}
