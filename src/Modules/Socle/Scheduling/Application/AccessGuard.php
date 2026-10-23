<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Scheduling\Infrastructure\PdoActorResolver;

/**
 * Qui voit quoi. scheduling.read (ou scheduling.manage) donne la vue d'ensemble de l'établissement ; sans elle, un
 * enseignant lit son propre emploi du temps, un apprenant celui de ses classes, un garant celui des classes de ses
 * apprenants. Toute autre personne est refusée.
 */
final class AccessGuard
{
    public const READ = 'scheduling.read';
    public const MANAGE = 'scheduling.manage';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PdoActorResolver $actors,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
    ) {
    }

    public function requireManage(Principal $principal, string $establishmentId): void
    {
        if (!$this->permissions->can($principal, self::MANAGE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de modifier le calendrier et les emplois du temps.');
        }
    }

    public function hasFullRead(Principal $principal, string $establishmentId): bool
    {
        return $this->permissions->can($principal, self::READ, $establishmentId) || $this->permissions->can($principal, self::MANAGE, $establishmentId);
    }

    /**
     * @return Scope|null null = tout l'établissement
     * @throws HttpException 403 si l'appelant n'a ni droit de lecture ni fiche de personne
     */
    public function scope(Principal $principal, string $establishmentId): ?Scope
    {
        if ($this->hasFullRead($principal, $establishmentId)) {
            return null;
        }
        $actor = $this->actors->personIdOf($principal->tenantId, $principal->userId);
        if ($actor === null) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter les emplois du temps.');
        }
        $persons = [$actor];
        foreach ($this->people->learnersOf($principal->tenantId, $actor) as $learner) {
            $persons[] = $learner->id;
        }
        $structures = [];
        foreach (array_unique($persons) as $personId) {
            foreach ($this->enrollments->forPerson($principal->tenantId, $personId) as $enrollment) {
                if ($enrollment->establishmentId === $establishmentId && $enrollment->structureId !== null
                    && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, 'conditional'], true)) {
                    $structures[] = $enrollment->structureId;
                }
            }
        }

        return new Scope($actor, array_values(array_unique($structures)));
    }
}
