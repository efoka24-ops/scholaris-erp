<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/** Périmètre de lecture des résultats pour un appelant sans droit pédagogique : lui-même et les apprenants dont il est garant. */
final class ResultAccess
{
    public function __construct(
        private readonly ActorDirectory $actors,
        private readonly PersonDirectory $people,
    ) {
    }

    /**
     * @return list<string> identifiants des personnes dont l'appelant peut lire les résultats publiés
     * @throws HttpException 403 si le compte n'est rattaché à aucune personne
     */
    public function visiblePersons(Principal $principal): array
    {
        $actor = $this->actors->personIdOf($principal->tenantId, $principal->userId);
        if ($actor === null) {
            throw HttpException::forbidden('Votre compte n\'est rattaché à aucune personne : aucun résultat n\'est consultable.');
        }
        $ids = [$actor];
        foreach ($this->people->learnersOf($principal->tenantId, $actor) as $learner) {
            $ids[] = $learner->id;
        }

        return array_values(array_unique($ids));
    }
}
