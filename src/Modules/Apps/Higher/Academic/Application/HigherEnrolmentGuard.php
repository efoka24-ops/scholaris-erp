<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Application;

use DateTimeInterface;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\EnrolmentEligibility;
use Scholaris\Modules\Apps\Higher\Academic\Domain\Regime;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuard;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentRequest;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;

/**
 * Garde d'inscription IPES (T219), enregistrée auprès du socle sous `organisation.enrolment.guards.higher` (D3) et exposée aux
 * campagnes par le contrat EnrolmentEligibility :
 *  - RG-ACA-1 / TST-01 : une autorisation MINESUP valide à la date doit couvrir la filière ET le niveau visé ;
 *  - RG-ACA-2 : une filière LMD doit avoir une université de tutelle pour l'année académique de l'inscription
 *    (rattachement par année, RG-ORG-8 : l'année N-1 garde le sien).
 * Une structure hors filière IPES (aucune ligne hi_programs) ou un établissement d'un autre type n'est pas concerné.
 */
final class HigherEnrolmentGuard implements EnrolmentGuard, EnrolmentEligibility
{
    public const CONTAINER_KEY = 'organisation.enrolment.guards.higher';

    public function __construct(
        private readonly Store $store,
        private readonly AuthorizationRegistry $authorizations,
        private readonly OrgUnitTree $tree,
    ) {
    }

    public function refusal(EnrolmentRequest $request): ?string
    {
        if ($request->establishmentType !== 'higher') {
            return null;
        }

        return $this->check($request->tenantId, $request->establishmentId, $request->trackId, $request->levelId, $request->academicYearId, $request->on);
    }

    public function refusalFor(string $tenantId, string $establishmentId, string $structureId, ?string $academicYearId, DateTimeInterface $on): ?string
    {
        if ($this->tree->structure($tenantId, $establishmentId, $structureId) === null) {
            return 'Structure introuvable dans cet établissement.';
        }
        $track = $this->tree->trackOf($tenantId, $establishmentId, $structureId);
        $level = $this->tree->levelOf($tenantId, $establishmentId, $structureId);

        return $this->check($tenantId, $establishmentId, $track, $level, $academicYearId, $on);
    }

    private function check(string $tenantId, string $establishmentId, ?string $trackId, ?string $levelId, ?string $academicYearId, DateTimeInterface $on): ?string
    {
        if ($trackId === null) {
            return null;
        }
        $program = $this->store->find('hi_programs', $tenantId, $establishmentId, $trackId);
        if ($program === null) {
            return null;
        }
        if ($program['status'] !== 'active') {
            return 'Cette filière est fermée : aucune inscription.';
        }
        if ($levelId === null) {
            return 'L\'inscription doit viser un niveau ou un groupe de la filière.';
        }
        if (!$this->authorizations->covers($tenantId, $establishmentId, $trackId, $levelId, $on)) {
            return 'Aucune autorisation MINESUP valide pour ce niveau de la filière à cette date (RG-ACA-1) : enregistrez l\'autorisation.';
        }
        if (Regime::tryFrom((string) $program['regime'])?->requiresTutelage() === true) {
            if ($academicYearId === null || $academicYearId === '') {
                return 'Filière LMD : l\'année académique de l\'inscription est requise pour contrôler la tutelle (RG-ACA-2).';
            }
            $tutelage = $this->store->select('hi_tutelages', $tenantId, $establishmentId, ['program_id' => $trackId, 'academic_year_id' => $academicYearId], 'id', 1);
            if ($tutelage === []) {
                return 'Filière LMD sans université de tutelle pour cette année académique (RG-ACA-2) : enregistrez la tutelle de l\'année.';
            }
        }

        return null;
    }
}
