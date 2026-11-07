<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Contracts;

/**
 * Règlement intérieur de l'établissement (EX-SEC-11) : valeur en vigueur d'une règle de la liste fermée (version de
 * l'établissement), sinon paramètre `secondary.<clé>` (plateforme, client, établissement), sinon la valeur par défaut fournie.
 * Aucune valeur réglementaire livrée : tout est vide tant que l'établissement ne l'a pas saisi (« à confirmer »).
 */
interface SchoolRules
{
    public function value(string $tenantId, string $establishmentId, string $key, mixed $default = null): mixed;
}
