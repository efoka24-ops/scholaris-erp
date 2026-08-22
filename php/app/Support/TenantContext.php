<?php

namespace App\Support;

/**
 * Etablissement courant de la requete.
 *
 * Rempli par le middleware ResolveTenant depuis l'utilisateur authentifie, puis
 * consomme par le scope global BelongsToTenant. Enregistre en singleton : une
 * instance par requete, jamais d'etat partage entre deux requetes.
 */
class TenantContext
{
    private ?string $tenantId = null;

    /**
     * Quand vrai, le scope global est desactive : reserve au Super Admin et aux
     * traitements hors requete (migrations de donnees, commandes artisan).
     */
    private bool $global = false;

    public function set(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function isGlobal(): bool
    {
        return $this->global;
    }

    /**
     * Execute le callback sans scoping tenant, puis restaure l'etat precedent
     * meme si le callback leve une exception.
     */
    public function global(callable $callback): mixed
    {
        $previous = $this->global;
        $this->global = true;

        try {
            return $callback();
        } finally {
            $this->global = $previous;
        }
    }
}
