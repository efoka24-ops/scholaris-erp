<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Amorce le premier administrateur d'un nouveau client (rôle `super_admin`, niveau client). Le compte reste « en attente » :
 * la personne l'active par code reçu (flux d'activation d'Identity). Utilisé à l'acceptation d'une demande d'accès.
 */
interface ClientAdministrators
{
    /**
     * @return array{person_id: string, user_id: string, login: string} `login` : identifiant de connexion (téléphone ou e-mail)
     * @throws \Scholaris\Core\Http\HttpException 422 si invalide, 409 si le téléphone ou l'e-mail est déjà utilisé dans ce client
     */
    public function invite(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email, string $locale, string $grantedBy): array;
}
