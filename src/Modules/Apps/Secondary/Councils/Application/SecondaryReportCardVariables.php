<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\ReportCardVariables;

/**
 * Fournisseur `assessment.report_card_variables.secondary` (T904) : variables `secondary_*` du bulletin produites par
 * ReportCardBuilder au moment où le socle émet le bulletin, et instantané enregistré (se_report_snapshots, une ligne par conseil
 * et par élève) pour la relecture à l'écran et la traçabilité. Hors d'une classe du secondaire : aucune variable.
 */
final class SecondaryReportCardVariables implements ReportCardVariables
{
    public const TABLE = 'se_report_snapshots';

    public function __construct(
        private readonly ReportCardBuilder $builder,
        private readonly CouncilDirectory $councils,
        private readonly Store $store,
        private readonly SecondaryClock $clock,
    ) {
    }

    public function for(string $tenantId, string $establishmentId, string $councilId, string $personId): array
    {
        $variables = $this->builder->build($tenantId, $establishmentId, $councilId, $personId);
        if ($variables === []) {
            return [];
        }
        $council = $this->councils->find($tenantId, $establishmentId, $councilId);
        $values = [
            'structure_id' => (string) ($council['structure_id'] ?? ''), 'period_id' => (string) ($council['period_id'] ?? ''),
            'variables' => json_encode($variables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'built_at' => $this->clock->now(),
        ];
        $this->store->transaction(function () use ($tenantId, $establishmentId, $councilId, $personId, $values): void {
            $updated = $this->store->update(self::TABLE, $tenantId, $establishmentId, $values, ['council_id' => $councilId, 'person_id' => $personId]);
            if ($updated === 0) {
                $this->store->insert(self::TABLE, $tenantId, $establishmentId, ['id' => (string) new Ulid(), 'council_id' => $councilId, 'person_id' => $personId] + $values);
            }
        });

        return $variables;
    }
}
