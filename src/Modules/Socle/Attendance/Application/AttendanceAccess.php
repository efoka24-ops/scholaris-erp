<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Attendance\Infrastructure\PdoActorDirectory;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Qui peut faire quoi. Refus par défaut : sans permission explicite, 403. Un parent (garant) lit l'assiduité de ses enfants ;
 * pour un apprenant majeur, il faut le consentement « parent_access ». Un apprenant lit la sienne.
 */
final class AttendanceAccess
{
    public const READ = 'attendance.read';
    public const TAKE = 'attendance.take';
    public const JUSTIFY = 'attendance.justify';
    public const DISCIPLINE_MANAGE = 'attendance.discipline.manage';
    public const DISCIPLINE_APPROVE = 'attendance.discipline.approve';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PdoActorDirectory $actors,
        private readonly PersonDirectory $people,
        private readonly ConsentChecker $consents,
        private readonly AttendanceClock $clock,
    ) {
    }

    public function can(Principal $principal, string $establishmentId, string ...$anyOf): bool
    {
        foreach ($anyOf as $permission) {
            if ($this->permissions->can($principal, $permission, $establishmentId)) {
                return true;
            }
        }

        return false;
    }

    public function require(Principal $principal, string $establishmentId, string ...$anyOf): void
    {
        if (!$this->can($principal, $establishmentId, ...$anyOf)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit d\'effectuer cette opération sur les présences.');
        }
    }

    public function personIdOf(Principal $principal): ?string
    {
        return $this->actors->personIdOf($principal->tenantId, $principal->userId);
    }

    /** Vrai si l'appelant est l'apprenant lui-même ou un de ses garants autorisés (consentement exigé pour un majeur). */
    public function isSelfOrGuardian(Principal $principal, string $learnerId): bool
    {
        $actor = $this->personIdOf($principal);
        if ($actor === null) {
            return false;
        }
        if ($actor === $learnerId) {
            return true;
        }
        $isGuarantor = false;
        foreach ($this->people->learnersOf($principal->tenantId, $actor) as $learner) {
            if ($learner->id === $learnerId) {
                $isGuarantor = true;
                break;
            }
        }
        if (!$isGuarantor) {
            return false;
        }
        $adult = $this->actors->isAdult($principal->tenantId, $learnerId, $this->clock->today());

        return !$adult || $this->consents->hasConsent($principal->tenantId, $learnerId, ConsentChecker::PARENT_ACCESS);
    }

    /** Lecture de l'assiduité d'un apprenant : droit de lecture de l'établissement, ou apprenant lui-même, ou garant. */
    public function canViewLearner(Principal $principal, string $establishmentId, string $learnerId): bool
    {
        return $this->can($principal, $establishmentId, self::READ) || $this->isSelfOrGuardian($principal, $learnerId);
    }
}
