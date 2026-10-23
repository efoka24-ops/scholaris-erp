<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

/**
 * Personne derrière un compte utilisateur. Lecture seule de la table `users` d'Identity (colonne person_id) :
 * à remplacer par un contrat Identity si celui-ci en publie un.
 */
final class PdoActorDirectory
{
    public function __construct(private readonly PdoStore $store)
    {
    }

    public function personIdOf(string $tenantId, string $userId): ?string
    {
        $rows = $this->store->rows('SELECT person_id FROM users WHERE tenant_id = ? AND id = ?', [$tenantId, $userId]);

        return isset($rows[0]['person_id']) ? (string) $rows[0]['person_id'] : null;
    }

    public function hasAccount(string $tenantId, string $personId): bool
    {
        return $this->store->rows('SELECT 1 AS x FROM users WHERE tenant_id = ? AND person_id = ? LIMIT 1', [$tenantId, $personId]) !== [];
    }
}
