<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Contracts;

/**
 * Point d'extension UNIQUE de la synchronisation par lots (PP6) : une application ou un module déclare un gestionnaire par
 * type d'élément (`kind`) sous la clé de conteneur `sync.handlers.<kind>` (ex. `sync.handlers.public.attendance`), dans son
 * propre definitions.php. Aucune application ne crée de route ou de file de synchronisation propre.
 *
 * Règles du contrat :
 *  - le gestionnaire contrôle lui-même le droit métier de $context->principal sur $context->establishmentId (le socle ne
 *    vérifie que le droit de soumettre un lot) ;
 *  - il est idempotent sur `client_uuid` (le socle écarte les rejeux d'un élément déjà appliqué, mais deux lots simultanés
 *    peuvent arriver ensemble) ;
 *  - un conflit avec la version du serveur est rendu par `ItemResult::conflict()` : jamais d'écrasement silencieux ;
 *  - une exception `HttpException` devient un résultat `rejected` (409 : `conflict`) ; toute autre exception devient un
 *    `rejected` générique, sans détail ni charge utile dans la réponse ni dans les journaux.
 */
interface BatchItemHandler
{
    public function handle(BatchItem $item, BatchContext $context): ItemResult;
}
