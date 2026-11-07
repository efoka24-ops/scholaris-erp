<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Crée le compte d'une personne que l'établissement vient d'inscrire. Le compte reste « en attente »
 * jusqu'à ce que la personne l'active par code reçu (SMS ou e-mail) et choisisse son mot de passe.
 */
interface AccountProvisioner
{
    /**
     * Fiche unique par client (FR-006) : le compte est rattaché à la fiche désignée par $personId, sinon à l'unique fiche
     * sans compte portant le même téléphone ou e-mail ET les mêmes prénom et nom ; une fiche n'est créée qu'à défaut.
     *
     * @return array{person_id: string, user_id: string, person_linked?: bool}
     * @throws \Scholaris\Core\Http\HttpException 409 si le téléphone ou l'e-mail est déjà utilisé dans ce client (ou si la
     *         fiche désignée a déjà un compte), 404 si la fiche désignée n'existe pas, 422 si invalide
     */
    public function provision(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email, string $locale = 'fr', ?string $personId = null): array;
}
