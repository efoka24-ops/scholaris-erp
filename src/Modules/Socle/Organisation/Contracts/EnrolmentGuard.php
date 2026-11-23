<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Point d'extension (D3) : garde-fou d'inscription fourni par une application (ex. Supérieur : autorisation du niveau et de
 * l'année, tutelle LMD). Enregistrement dans le conteneur sous une entrée de liste `organisation.enrolment.guards.<application>`
 * (voir EnrolmentGuards::PREFIX). Appelé par People à la validation d'une inscription, après le contrôle d'autorisation du socle.
 * Un garde ne concerne en général qu'un type d'établissement : il renvoie null pour les autres.
 */
interface EnrolmentGuard
{
    /** @return string|null motif du refus (affiché à l'utilisateur), null si l'inscription est permise */
    public function refusal(EnrolmentRequest $request): ?string;
}
