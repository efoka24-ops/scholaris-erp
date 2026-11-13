<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Contracts;

use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;

/**
 * Émission d'un document du secondaire pour un autre sous-module (Enrolment : fiche de préinscription DOC-01, exeat DOC-05 après la
 * clôture de l'inscription ; lots suivants : leurs propres documents). Numéro, QR, contenu figé et langue viennent du socle Documents.
 * Le module dont dépend le type doit être actif (409 `module_not_enabled`) ; toute capacité de profil déclarée par le type doit
 * également être offerte (409 `profile_capability_not_offered`).
 *
 * Erreurs (HttpException) : 422 nature inconnue, variable non déclarée ou modèle absent ; 409 module/capacité indisponible ; 404 établissement.
 */
interface SecondaryDocuments
{
    /**
     * @param array<string, scalar|null> $values variables propres au type (année, classe... ; voir le type au socle)
     * @param string|null $locale fr ou en ; null = langue de la section de l'établissement (RG-DOC-5)
     */
    public function issue(string $tenantId, string $establishmentId, string $kind, string $personId, array $values, string $issuedBy, ?string $locale = null): IssuedDocument;
}
