<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Création des administrateurs de plateforme (rôle global `platform_admin`, client technique `platform`).
 * Seule voie de création : la commande console `platform:admin-create`. Aucune route HTTP, aucun administrateur de client.
 */
interface PlatformAdministrators
{
    /**
     * Crée la personne, un compte ACTIF avec un mot de passe généré (affiché une seule fois par l'appelant) et le rôle.
     *
     * @return array{person_id: string, user_id: string, login: string, password: string}
     * @throws \Scholaris\Core\Http\HttpException 422 identifiant ou nom invalide, 409 identifiant déjà utilisé
     */
    public function create(string $identifier, string $firstName, string $lastName): array;
}
