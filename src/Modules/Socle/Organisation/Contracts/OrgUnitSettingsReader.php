<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Lecture des paramètres à la portée d'une unité interne (D6, RG-ORG-5) : plateforme > client > établissement > unités parentes
 * > unité (la plus spécifique l'emporte). Extension de SettingsReader, dont la signature reste inchangée pour les clients existants.
 */
interface OrgUnitSettingsReader extends SettingsReader
{
    /**
     * Valeur effective de la clé pour l'unité (école, département) de l'établissement, sinon $default. Avec $orgUnitId nul, se comporte
     * comme value(). Une unité qui n'appartient pas à l'établissement du client est refusée (404).
     */
    public function valueForOrgUnit(string $tenantId, string $establishmentId, ?string $orgUnitId, string $key, mixed $default = null): mixed;
}
