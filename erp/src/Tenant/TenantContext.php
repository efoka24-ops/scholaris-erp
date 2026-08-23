<?php

declare(strict_types=1);

namespace Scholaris\Tenant;

use RuntimeException;

/**
 * Etablissement courant de la requete.
 *
 * Rempli une seule fois, juste apres l'authentification, puis consomme par
 * chaque requete SQL construite via Table. Tant qu'il n'est pas renseigne,
 * toute lecture sur une table scopee echoue : le defaut est le refus, pas
 * l'acces total. Une erreur de cablage provoque donc une panne visible, jamais
 * une fuite silencieuse de donnees entre etablissements.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private bool $global = false;

    public function set(string $tenantId): void
    {
        // Une chaine vide passerait pour un etablissement valide et ferait
        // remonter zero ligne partout, sans erreur visible. Mieux vaut refuser.
        if ($tenantId === '') {
            throw new RuntimeException('Identifiant d etablissement vide.');
        }

        $this->tenantId = $tenantId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }

    public function isSet(): bool
    {
        return $this->tenantId !== null;
    }

    public function isGlobal(): bool
    {
        return $this->global;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Identifiant de l'etablissement courant, ou erreur si aucun n'est defini.
     * Appele par Table avant chaque requete scopee.
     */
    public function requireId(): string
    {
        if ($this->tenantId === null) {
            throw new RuntimeException(
                'Aucun etablissement courant : une requete scopee a ete tentee hors session authentifiee. '
                .'Utiliser TenantContext::global() pour les traitements volontairement inter-etablissements.'
            );
        }

        return $this->tenantId;
    }

    /**
     * Execute le callback sans filtrage par etablissement.
     *
     * Reserve au Super Admin et aux traitements hors requete (migrations,
     * scripts). Volontairement explicite et facile a reperer dans le code :
     * chaque appel est un endroit ou l'isolation est levee sciemment.
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
