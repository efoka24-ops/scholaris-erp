<?php

declare(strict_types=1);

namespace Scholaris\View;

use RuntimeException;

/**
 * Rendu des gabarits PHP.
 *
 * L'echappement est la valeur par defaut : les gabarits appellent $this->e()
 * pour afficher une donnee, et la seule facon d'ecrire du HTML brut est
 * $this->raw(), volontairement voyant. Rendre l'echappement optionnel ferait de
 * chaque oubli une faille XSS.
 */
final class View
{
    private string $directory;

    /** @var array<string, mixed> Variables partagees par tous les gabarits. */
    private array $shared = [];

    /** @var array<string, mixed> */
    private array $data = [];

    private ?string $layout = null;

    /** @var array<string, string> */
    private array $sections = [];

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data = []): string
    {
        $renderer = clone $this;
        $renderer->data = array_merge($this->shared, $data);

        $content = $renderer->evaluate($template, $renderer->data);

        // Le gabarit a declare un layout : son rendu devient la section
        // "content" du layout, qui est ensuite rendu a son tour.
        if ($renderer->layout !== null) {
            $layout = $renderer->layout;
            $renderer->sections['content'] = $content;
            $renderer->layout = null;

            $content = $renderer->evaluate($layout, $renderer->data);
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function evaluate(string $template, array $data): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.str_replace('.', DIRECTORY_SEPARATOR, $template).'.php';

        if (! is_file($path)) {
            throw new RuntimeException("Gabarit introuvable : {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $path;
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /** Declare le layout dans lequel s'insere le gabarit courant. */
    public function extends(string $layout): void
    {
        $this->layout = $layout;
    }

    public function section(string $name): string
    {
        return $this->sections[$name] ?? '';
    }

    public function set(string $name, string $value): void
    {
        $this->sections[$name] = $value;
    }

    /**
     * Inclut un fragment de gabarit.
     *
     * @param  array<string, mixed>  $data
     */
    public function include(string $template, array $data = []): string
    {
        return $this->evaluate($template, array_merge($this->data, $data));
    }

    /** Echappe une valeur pour insertion dans du HTML. */
    public function e(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Insere du HTML sans echappement.
     *
     * A n'utiliser que sur du contenu produit par l'application. Ne jamais y
     * passer une donnee saisie par un utilisateur.
     */
    public function raw(string $html): string
    {
        return $html;
    }

    /** Montant en francs CFA, separateurs francais. */
    public function money(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' FCFA';
    }

    public function number(mixed $value): string
    {
        return number_format((float) $value, 0, ',', ' ');
    }

    /** Date au format jour/mois/annee, ou tiret si absente. */
    public function date(mixed $value, string $format = 'd/m/Y'): string
    {
        if (! is_string($value) || $value === '') {
            return '-';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '-' : date($format, $timestamp);
    }
}
