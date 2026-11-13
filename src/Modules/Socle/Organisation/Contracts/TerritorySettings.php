<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Paramètres hérités le long de la hiérarchie territoriale (PP16) : national (le client) > région > département > arrondissement >
 * établissement, le plus spécifique l'emporte SAUF verrouillage. Chaque entrée porte un niveau de verrouillage : le niveau le plus
 * profond qui peut encore la modifier (`national` = seul le national, `region` = national et région, etc.). Fourni par Organisation.
 */
interface TerritorySettings
{
    public const NATIONAL = 'national';
    public const REGION = 'region';
    public const DEPARTMENT = 'departement';
    public const DISTRICT = 'arrondissement';
    public const ESTABLISHMENT = 'establishment';

    /**
     * Définit la valeur à un territoire ($territoryId nul = niveau national). Refusé (403) si un niveau supérieur verrouille la clé
     * au-dessus du niveau de ce territoire ; 404 si le territoire est inconnu ; 422 si la clé, la valeur ou le verrou sont invalides
     * (le verrou ne peut pas être plus haut que le niveau de l'entrée).
     */
    public function set(string $tenantId, ?string $territoryId, string $key, mixed $value, string $lockLevel, string $actorId): void;

    /**
     * Valeur effective pour l'établissement : entrées territoriales (national puis région...arrondissement) et paramètre propre de
     * l'établissement, sous réserve des verrous ; à défaut, le paramètre du client ou de la plateforme, sinon $default.
     */
    public function value(string $tenantId, string $establishmentId, string $key, mixed $default = null): mixed;
}
