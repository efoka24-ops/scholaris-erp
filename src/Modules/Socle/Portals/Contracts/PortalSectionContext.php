<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Contracts;

/**
 * Contexte d'une section : portail, personne connectée, personne affichée (l'étudiant lui-même ou l'enfant du garant)
 * et niveau d'accès (`$full` faux = consentement absent : identité, inscriptions et solde seulement).
 */
final class PortalSectionContext
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $portal,
        public readonly string $viewerPersonId,
        public readonly string $subjectPersonId,
        public readonly bool $full,
    ) {
    }
}
