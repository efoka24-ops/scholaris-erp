<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Contracts;

/**
 * Vitrine publique des admissions (page « Candidater », sans authentification) offerte à Apps\HigherApply. N'expose que le strict
 * nécessaire à un candidat : nom de l'institut, filière et niveau, dates, places, frais de dossier, pièces demandées. Les
 * identifiants de client et d'établissement ne sortent jamais vers le navigateur ; le jeton du lien ne se déduit d'aucun autre.
 */
interface PublicAdmissions
{
    /**
     * Institut du lien, ou null si le lien est inconnu, désactivé ou si l'application Supérieur n'est pas ouverte à son client
     * (même réponse pour les trois cas : le lien ne révèle rien).
     */
    public function resolve(string $slug): ?PublicInstitute;

    /**
     * Campagnes ouvertes aux candidatures aujourd'hui (statut ouvert, dates comprises).
     *
     * @return list<array{id: string, title: string, school: string, program: string, level: string, opens_on: string, closes_on: string,
     *                    seats: int, application_fee_xaf: int, selection_mode: string, required_documents: list<array{code: string, label: string, blocking: bool}>}>
     */
    public function openCampaigns(string $tenantId, string $establishmentId): array;
}
