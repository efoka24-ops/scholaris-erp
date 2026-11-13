<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Refus explicite d'un consentement, à distinguer de son absence : vrai quand la dernière décision enregistrée pour cette finalité
 * est une révocation. Une personne sans aucune décision n'a rien refusé. Fourni par le module People.
 */
interface ConsentRefusals
{
    public function hasRevoked(string $tenantId, string $personId, string $purpose): bool;
}
