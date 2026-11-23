<?php

declare(strict_types=1);

namespace Scholaris\Core\Security;

/** Personne authentifiée : identifiant d'utilisateur et client (tenant) auquel elle appartient. */
final class Principal
{
    public function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
    ) {
    }
}
