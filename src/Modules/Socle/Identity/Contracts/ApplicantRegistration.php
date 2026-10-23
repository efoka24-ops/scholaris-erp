<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Inscription publique d'un candidat (page « Candidater » d'un établissement, sans authentification) : crée la fiche et un
 * compte « en attente », lui donne le seul rôle de libre-service `apprenant` dans l'établissement concerné, puis envoie le code
 * d'activation (SMS ou e-mail) du parcours d'activation existant (/auth/activation/verify puis /auth/activation/password).
 *
 * Sûreté : aucun droit n'est jamais ajouté à un compte existant autre qu'un compte qui ne porte que des rôles de libre-service ;
 * la réponse est identique que le téléphone ou l'e-mail soit déjà connu ou non ; une fiche déjà enregistrée sans compte (même
 * contact, mêmes prénom et nom) est réutilisée (pas de doublon de personne).
 */
interface ApplicantRegistration
{
    /**
     * @param string $identifier téléphone (+237…) ou adresse e-mail de la personne, canal du code
     * @return array{channel: string, masked_to: string, expires_in: int, resend_in: int}
     * @throws \Scholaris\Core\Http\HttpException 422 identifiant ou nom invalide, 429 trop de demandes pour cet identifiant
     */
    public function register(string $tenantId, string $establishmentId, string $firstName, string $lastName, string $identifier, string $locale = 'fr'): array;
}
