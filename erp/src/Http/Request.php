<?php

declare(strict_types=1);

namespace Scholaris\Http;

/**
 * Requete HTTP entrante.
 *
 * Les acces aux donnees utilisateur passent par des methodes typees plutot que
 * par $_GET / $_POST directement : la valeur retournee est toujours du type
 * attendu, ce qui evite de propager des tableaux ou des null inattendus dans le
 * code metier.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, mixed> */
    private array $body;

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, string> */
    private array $attributes = [];

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $server
     */
    public function __construct(array $query, array $body, array $server)
    {
        $this->query = $query;
        $this->body = $body;
        $this->server = $server;
    }

    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER);
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));

        // Les navigateurs n'emettent que GET et POST : les formulaires signalent
        // PUT/PATCH/DELETE via un champ cache _method.
        if ($method === 'POST') {
            $override = strtoupper((string) ($this->body['_method'] ?? ''));

            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        return '/'.trim($path, '/');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function ip(): ?string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->input($key) ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return $value !== null && $value !== '' ? (int) $value : $default;
    }

    public function boolean(string $key): bool
    {
        return in_array(strtolower($this->input($key) ?? ''), ['1', 'true', 'on', 'yes'], true);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && $value !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** Parametres extraits de l'URL par le routeur (ex: /students/{id}). */
    public function setAttribute(string $key, string $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, ?string $default = null): ?string
    {
        return $this->attributes[$key] ?? $default;
    }
}
