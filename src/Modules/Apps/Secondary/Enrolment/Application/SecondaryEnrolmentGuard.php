<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Application;

use DateTimeInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\StudentRegistry;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuard;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentRequest;

/**
 * Garde d'inscription du secondaire (T825), enregistrée auprès du socle sous `organisation.enrolment.guards.secondary` (D3) : elle
 * s'applique à TOUTE inscription d'un établissement secondaire ouvert, qu'elle vienne de l'application ou de la route du socle.
 *  - classe non paramétrée (aucun profil de classe) : refus ;
 *  - RG-ORG-1 : cycle de la classe absent du profil de l'établissement : refus ;
 *  - RG-ORG-3 : module `authorizations` actif : une autorisation valide à la date doit couvrir la classe (ou son niveau) ;
 *  - RG-INS-3 : élève exclu définitivement sans décision motivée de réadmission pour l'année : refus.
 * L'âge (RG-INS-2) n'est pas un refus : c'est une alerte qui exige la décision du chef (contrôlée par EnrolmentService).
 * Jamais de contrôle de paiement (RG-INS-4). Établissement d'un autre type, ou secondaire pas encore ouvert : non concerné.
 */
final class SecondaryEnrolmentGuard implements EnrolmentGuard
{
    public const CONTAINER_KEY = 'organisation.enrolment.guards.secondary';

    public function __construct(
        private readonly EstablishmentProfiles $profiles,
        private readonly ClassProfiles $classes,
        private readonly StudentRegistry $students,
        private readonly AuthorizationRegistry $authorizations,
    ) {
    }

    public function refusal(EnrolmentRequest $request): ?string
    {
        if ($request->establishmentType !== 'secondary') {
            return null;
        }

        return $this->check($request->tenantId, $request->establishmentId, $request->structureId, $request->levelId, $request->academicYearId, $request->personId, $request->on);
    }

    public function check(string $tenantId, string $establishmentId, string $structureId, ?string $levelId, ?string $academicYearId, ?string $personId, DateTimeInterface $on): ?string
    {
        try {
            $profile = $this->profiles->forEstablishment($tenantId, $establishmentId);
        } catch (HttpException) {
            return null;
        }
        if (!$profile->stored) {
            return null;
        }
        $class = $this->classes->find($tenantId, $establishmentId, $structureId);
        if ($class === null) {
            return 'Classe non paramétrée dans l\'application secondaire : créez-la depuis le paramétrage (niveau, série ou spécialité).';
        }
        if (!$profile->offersCycle($class->cycleCode)) {
            return 'Le cycle de cette classe n\'est pas offert par le profil de l\'établissement (RG-ORG-1).';
        }
        if ($profile->isActive(Modules::AUTHORIZATIONS) && !$this->authorizations->covers($tenantId, $establishmentId, $structureId, $levelId, $on)) {
            return 'Aucune autorisation valide à cette date ne couvre cette classe, sa série ou son cycle (RG-ORG-3) : enregistrez l\'autorisation.';
        }
        if ($personId !== null) {
            $student = $this->students->profile($tenantId, $establishmentId, $personId);
            if ($student !== null && $student->finalExclusionOn !== null && ($academicYearId === null || $student->readmissionYearId !== $academicYearId)) {
                return 'Élève exclu définitivement de l\'établissement : la réinscription exige une décision motivée du chef d\'établissement (RG-INS-3).';
            }
        }

        return null;
    }
}
