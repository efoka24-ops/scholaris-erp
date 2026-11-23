<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Contracts;

/**
 * Profil effectif d'un établissement (contrat interne de Secondary, fourni par Setup\Application\ProfileResolver) : utilisé par
 * la garde de module et par tout sous-module qui doit savoir quels cycles, enseignements ou modules l'établissement a.
 * Établissement inconnu : 404 ; établissement sans type secondaire valide : 422 (type absent ou inconnu, RG-SEC-1).
 */
interface EstablishmentProfiles
{
    public function forEstablishment(string $tenantId, string $establishmentId): EstablishmentProfile;
}
