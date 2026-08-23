<?php

declare(strict_types=1);

namespace Scholaris\Security;

use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;

/**
 * Protection contre la falsification de requete inter-site.
 *
 * Un jeton aleatoire est place en session et repris dans chaque formulaire.
 * La verification est appliquee par le point d'entree a toutes les requetes qui
 * modifient des donnees, et non ajoutee formulaire par formulaire : sans cela,
 * un site tiers pourrait faire executer une action au nom de l'utilisateur
 * connecte (supprimer un eleve, enregistrer un paiement).
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * @throws HttpException 419 si le jeton est absent ou ne correspond pas.
     */
    public function verify(Request $request): void
    {
        $expected = $this->session->get(self::SESSION_KEY);
        $provided = $request->input('_token') ?? '';

        // hash_equals compare en temps constant : une comparaison classique
        // laisse deviner le jeton caractere par caractere.
        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $provided)) {
            throw new HttpException(419);
        }
    }

    /** Renouvelle le jeton, a la connexion comme a la deconnexion. */
    public function rotate(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }
}
