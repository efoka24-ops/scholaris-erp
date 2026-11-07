<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Contracts;

/**
 * Garde de création ou de modification d'un frais du catalogue (SS8), appelée par `POST /fee-items` et `PATCH /fee-items/{id}`.
 * Exemple : catalogue de frais autorisés d'un établissement public (RG-FIN-2). Renvoie null pour laisser passer, ou le motif du
 * refus (réponse 422, code `fee_not_authorized`). Une garde ignore les établissements qui ne la concernent pas.
 *
 * Enregistrement : clé de liste du conteneur `billing.fee_item_guards.<application>` => [MaGarde::class, ...]
 * (plusieurs applications s'ajoutent sans s'écraser). Aucune garde enregistrée : comportement inchangé.
 */
interface FeeItemGuard
{
    public function refusal(FeeItemDraft $draft): ?string;
}
