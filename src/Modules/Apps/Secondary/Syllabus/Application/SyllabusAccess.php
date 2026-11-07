<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Syllabus\Application;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;

/**
 * Droits du cahier de textes (T862, T863) : lecture par le censeur, l'animateur pédagogique et les lecteurs du cahier ; écriture
 * des séances par l'enseignant (R09) dans ses classes ; progressions par l'animateur ; visas du censeur (`censor`) et de
 * l'animateur (`head_of_dept`). L'inspecteur pédagogique (R21, compte externe daté) lit par `secondary.inspection.read` avec un
 * périmètre territorial borné à l'établissement visité, ou une affectation datée sur l'établissement ou une classe ; chacune de
 * ses lectures est journalisée (Q13). La durée de validité de l'affectation est tenue par Identity.
 */
final class SyllabusAccess
{
    public const READ = ['secondary.syllabus.read', 'secondary.syllabus.manage', 'secondary.syllabus.visa'];
    public const LOG = ['secondary.lesson.log'];
    public const MANAGE = ['secondary.syllabus.manage'];
    public const INSPECT = 'secondary.inspection.read';
    /** Permission => rôle du visa (EX-CTX-04). */
    public const VISA_ROLES = ['secondary.syllabus.visa' => 'censor', 'secondary.syllabus.manage' => 'head_of_dept'];
    public const REMIND = ['secondary.syllabus.visa', 'secondary.syllabus.manage', 'secondary.timetable.manage'];

    public function __construct(
        private readonly Access $access,
        private readonly PermissionChecker $permissions,
        private readonly PermissionScopes $scopes,
    ) {
    }

    /**
     * Périmètre de lecture (lecteurs, enseignant dans ses classes, inspecteur) ; 403 s'il est vide.
     *
     * @return array{0: Perimeter, 1: bool} périmètre, vrai si la lecture ne tient qu'au droit d'inspection (à journaliser)
     */
    public function readable(Principal $principal, string $establishmentId): array
    {
        $staff = $this->access->perimeter($principal, $establishmentId, [...self::READ, ...self::LOG]);
        $inspection = $this->inspection($principal, $establishmentId);
        if ($staff->isEmpty() && $inspection->isEmpty()) {
            $this->access->require($principal, $establishmentId, [...self::READ, ...self::LOG, self::INSPECT]);
        }

        return [$staff->union($inspection), $staff->isEmpty()];
    }

    /** Journal de lecture d'un inspecteur (objet lu et son identifiant). */
    public function logInspection(Principal $principal, bool $inspector, string $objectType, string $objectId): void
    {
        if ($inspector) {
            $this->access->audit($principal, 'secondary.inspection.read', $objectType, $objectId);
        }
    }

    private function inspection(Principal $principal, string $establishmentId): Perimeter
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, [self::INSPECT]);
        if (!$this->permissions->can($principal, self::INSPECT, $establishmentId)) {
            return $perimeter;
        }
        foreach ($this->scopes->scopesFor($principal, self::INSPECT, $establishmentId) as $scope) {
            if ($scope->type === Scope::TERRITORY && $scope->establishmentId === $establishmentId && $scope->tenantId === $principal->tenantId) {
                return new Perimeter($principal->tenantId, $establishmentId, true);
            }
        }

        return $perimeter;
    }
}
