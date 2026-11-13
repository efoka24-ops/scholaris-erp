<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Date et lieu de naissance déclarés à l'entrée d'une personne par une application (ex. candidature IPES), pour que les règles du
 * socle (majorité, garant d'un mineur) s'appliquent sans saisie supplémentaire. Fourni par People, borné au client.
 */
interface BirthDateWriter
{
    /**
     * @param string $birthDate AAAA-MM-JJ, passée
     * @throws \Scholaris\Core\Http\HttpException 404 personne inconnue dans ce client, 422 date invalide
     */
    public function record(string $tenantId, string $personId, string $birthDate, ?string $birthPlace = null): void;
}
