<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;

/**
 * Un enseignant ne saisit que ses épreuves et ses élèves (TeachingScope de People). Celui qui détient le droit de
 * validation (censeur, direction) saisit pour toute la classe.
 */
final class ScopeGuard
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ActorDirectory $actors,
        private readonly TeachingScope $teaching,
    ) {
    }

    /**
     * Un enseignant ne crée d'épreuve que pour une classe où il a au moins un élève.
     *
     * @param list<string> $personIds élèves de la classe
     * @throws HttpException 403
     */
    public function assertTeachesAny(Principal $principal, array $personIds): void
    {
        $actor = $this->actors->personIdOf($principal->tenantId, $principal->userId);
        if ($actor === null) {
            throw HttpException::forbidden('Votre compte n\'est rattaché à aucune personne : vous ne pouvez pas créer d\'épreuve.');
        }
        if (array_intersect($personIds, $this->teaching->learnerIdsOf($principal->tenantId, $actor)) === []) {
            throw HttpException::forbidden('Cette classe ne fait pas partie de vos classes. Créez des épreuves uniquement pour vos élèves.');
        }
    }

    /**
     * @param array<string, mixed> $assessment
     * @param list<string> $personIds élèves concernés par la saisie
     * @throws HttpException 403
     */
    public function assertCanEnter(Principal $principal, string $establishmentId, array $assessment, array $personIds): void
    {
        if ($this->permissions->can($principal, 'assessment.validate', $establishmentId)) {
            return;
        }
        $actor = $this->actors->personIdOf($principal->tenantId, $principal->userId);
        if ($actor === null) {
            throw HttpException::forbidden('Votre compte n\'est rattaché à aucune personne : vous ne pouvez pas saisir de notes.');
        }
        $owner = $assessment['teacher_person_id'] ?? null;
        if ($owner !== null && $owner !== $actor) {
            throw HttpException::forbidden('Cette épreuve est confiée à un autre enseignant. Demandez à la direction de vous l\'attribuer.');
        }
        $allowed = $this->teaching->learnerIdsOf($principal->tenantId, $actor);
        if (array_diff($personIds, $allowed) !== []) {
            throw HttpException::forbidden('Certains élèves ne font pas partie de vos classes. Saisissez uniquement les notes de vos élèves.');
        }
    }
}
