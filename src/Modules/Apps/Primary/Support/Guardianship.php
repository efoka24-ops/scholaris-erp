<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Portail parent : la personne connectée doit être garante de l'enfant ET l'enfant inscrit (actif ou sous condition) dans cet
 * établissement ; sinon 404 (l'existence est cachée). Permission `self.read` exigée.
 */
final class Guardianship
{
    public const PERMISSION = ['self.read'];
    private const LIVE = ['active', 'conditional'];

    public function __construct(
        private readonly Access $access,
        private readonly UserPersonLink $links,
        private readonly PersonDirectory $people,
        private readonly EnrollmentDirectory $enrollments,
    ) {
    }

    /** @return string identifiant de la personne du parent */
    public function assertChild(Principal $principal, string $establishmentId, string $childId): string
    {
        $this->access->require($principal, $establishmentId, self::PERMISSION);
        $t = $principal->tenantId;
        $guardian = $this->links->personIdForUser($t, $principal->userId) ?? throw HttpException::notFound('Compte parent introuvable.');
        $mine = false;
        foreach ($this->people->learnersOf($t, $guardian) as $child) {
            $mine = $mine || $child->id === $childId;
        }
        $enrolled = false;
        foreach ($mine ? $this->enrollments->forPerson($t, $childId) : [] as $enrollment) {
            $enrolled = $enrolled || ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, self::LIVE, true));
        }
        if (!$enrolled) {
            throw HttpException::notFound('Enfant introuvable.');
        }

        return $guardian;
    }
}
