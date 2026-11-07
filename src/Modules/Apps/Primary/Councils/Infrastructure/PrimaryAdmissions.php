<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Councils\Infrastructure;

use Scholaris\Modules\Apps\Primary\Councils\Application\CouncilService;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;

/**
 * Source d'admissions du primaire (`assessment.admission_sources.primary`) : élèves dont la décision du conseil VALIDÉ du dernier
 * trimestre est « admis ». People\YearPromotion les promeut à la clôture vers la classe désignée par `people.promotion.next_structure`
 * (brouillon d'inscription) ; « redouble » et « fin de cycle » ne sont jamais promus (RG-PRI-17, 18).
 */
final class PrimaryAdmissions implements AdmissionDirectory
{
    public function __construct(private readonly Store $store)
    {
    }

    public function admittedPersonIds(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        $ids = [];
        foreach ($this->store->select(CouncilService::COUNCILS, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId, 'is_final_term' => 1, 'status' => 'validated'], 'id', 1000) as $council) {
            foreach ($this->store->select(CouncilService::LINES, $tenantId, $establishmentId, ['council_id' => (string) $council['id'], 'decision' => 'admitted'], 'person_id', 5000) as $line) {
                $ids[] = (string) $line['person_id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
