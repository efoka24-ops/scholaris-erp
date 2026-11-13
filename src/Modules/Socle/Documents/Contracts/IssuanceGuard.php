<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/**
 * Garde de précondition appelée avant toute émission (S18) : API, contrat, lot, demande. Exemples : PV signé avant un relevé,
 * solde nul avant un quitus, éligibilité, titre interdit. Renvoie null pour laisser passer, ou le motif du refus (réponse 422).
 *
 * Enregistrement : clé de liste du conteneur `documents.issuance_guards.<application>` => [MaGarde::class, ...]
 * (plusieurs applications s'ajoutent sans s'écraser). Une garde ignore les types qui ne la concernent pas.
 */
interface IssuanceGuard
{
    public function refusal(IssuanceContext $context): ?string;
}
