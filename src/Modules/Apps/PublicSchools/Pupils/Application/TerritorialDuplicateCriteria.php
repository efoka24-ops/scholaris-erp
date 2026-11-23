<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use Scholaris\Core\Tenancy\MissingTenantContext;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Socle\People\Contracts\DuplicateCriteriaProvider;

/**
 * Critères de doublon des écoles publiques (PP8, `people.duplicate_criteria.public_schools`) : la détection du socle (nom, date de
 * naissance) se limite aux écoles du département de l'établissement actif, et seulement quand cet établissement est une école publique.
 */
final class TerritorialDuplicateCriteria implements DuplicateCriteriaProvider
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SchoolRegistry $schools,
        private readonly DuplicateScope $scope,
    ) {
    }

    public function establishmentScope(string $tenantId): ?array
    {
        try {
            if (!$this->context->hasTenant() || $this->context->tenantId() !== $tenantId) {
                return null;
            }
            $establishmentId = $this->context->establishmentId();
        } catch (MissingTenantContext) {
            return null;
        }
        if ($this->schools->find($tenantId, $establishmentId) === null) {
            return null;
        }

        return $this->scope->establishments($tenantId, $establishmentId);
    }

    public function candidateIds(string $tenantId, array $input): array
    {
        return [];
    }

    public function extraCriteria(string $tenantId, array $input, array $existing): array
    {
        return [];
    }
}
