<?php

declare(strict_types=1);

namespace Scholaris\Security;

/**
 * Session utilisateur.
 *
 * Le cookie est pose en HttpOnly et SameSite=Lax : inaccessible au JavaScript,
 * et non transmis lors des navigations declenchees par un autre site, ce qui
 * ferme la principale voie du vol de session.
 */
final class Session
{
    private bool $started = false;

    public function start(bool $secure): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            // Cookie limite a HTTPS en production. Reste desactive tant que le
            // certificat du sous-domaine n'est pas en place, sinon la session
            // ne s'etablit jamais.
            'secure' => $secure,
        ]);

        session_name('scholaris_session');
        session_start();

        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Regenere l'identifiant de session. Appele a la connexion pour empecher
     * la fixation de session : un identifiant obtenu avant authentification ne
     * doit jamais rester valable apres.
     */
    public function regenerate(): void
    {
        // Impossible une fois les en-tetes envoyes : c'est le cas en ligne de
        // commande et dans les tests, ou la session est simulee en memoire.
        if (session_status() === PHP_SESSION_ACTIVE && ! headers_sent()) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        $this->started = false;
    }

    /** Message a afficher une seule fois, sur la page suivante. */
    public function flash(string $key, string $message): void
    {
        $messages = $this->get('_flash', []);
        $messages[$key] = $message;
        $this->set('_flash', $messages);
    }

    public function pullFlash(string $key): ?string
    {
        $messages = $this->get('_flash', []);
        $message = $messages[$key] ?? null;

        unset($messages[$key]);
        $this->set('_flash', $messages);

        return is_string($message) ? $message : null;
    }

    /**
     * Conserve les valeurs saisies pour re-remplir un formulaire refuse.
     *
     * @param  array<string, mixed>  $input
     */
    public function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $this->set('_old', $input);
    }

    /**
     * @return array<string, mixed>
     */
    public function pullOldInput(): array
    {
        $old = $this->get('_old', []);
        $this->forget('_old');

        return is_array($old) ? $old : [];
    }
}
