<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Contracts;

/**
 * Contrat ENTRANT local : l'apprenant est-il majeur ? People\Contracts\PersonSummary n'expose pas la date de naissance.
 * Réponse null = inconnu : le portail traite alors l'apprenant comme majeur (consentement parent_access exigé).
 */
interface AdultLearnerCheck
{
    public function isAdult(string $tenantId, string $personId): ?bool;
}
