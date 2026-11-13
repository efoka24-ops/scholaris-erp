<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Grades\Infrastructure;

use Scholaris\Modules\Apps\Primary\Grades\Application\ConductService;
use Scholaris\Modules\Apps\Primary\Support\ScaleProvider;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilLineProvider;

/**
 * Préremplissage des lignes du conseil (PS4, EX-PRI-31) : la conduite saisie par le maître pour la période devient la proposition de
 * conduite de l'élève au conseil (libellé de l'échelle de l'école). Le socle n'écrase jamais une valeur saisie en séance ; un élève
 * sans conduite saisie n'a aucune proposition. Hors d'une classe primaire (aucune conduite), le fournisseur répond « rien ».
 */
final class PrimaryCouncilLines implements CouncilLineProvider
{
    public function __construct(private readonly Store $store, private readonly ScaleProvider $scales)
    {
    }

    public function prefill(string $tenantId, string $establishmentId, string $structureId, string $periodId, array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $labels = [];
        foreach ($this->scales->conduct($tenantId, $establishmentId) as $level) {
            $labels[$level['code']] = $level['label_fr'];
        }
        $wanted = array_flip($personIds);
        $lines = [];
        foreach ($this->store->select(ConductService::TABLE, $tenantId, $establishmentId, ['class_structure_id' => $structureId, 'period_id' => $periodId], 'person_id', 5000) as $row) {
            $person = (string) $row['person_id'];
            if (isset($wanted[$person])) {
                $lines[$person] = ['conduct' => $labels[(string) $row['scale_code']] ?? (string) $row['scale_code']];
            }
        }

        return $lines;
    }
}
