<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;

/** Lecture validée d'une requête du module : personne connectée, établissement actif, corps JSON en liste blanche, erreurs 422. */
final class Input
{
    public const ID = '/^[A-Za-z0-9_-]{1,40}$/';

    /** @var array<string, mixed> */
    private array $body;

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param list<string> $allowed champs permis (tout autre champ est refusé) */
    public function __construct(private readonly ServerRequestInterface $request, array $allowed = [], bool $withBody = false)
    {
        $this->body = [];
        if ($withBody) {
            $raw = trim((string) $request->getBody());
            $data = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
            }
            $unknown = array_diff(array_map('strval', array_keys($data)), $allowed);
            if ($unknown !== []) {
                throw HttpException::validation(array_fill_keys(array_values($unknown), ['Champ non autorisé.']));
            }
            $this->body = $data;
        }
    }

    public static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');

        return $principal instanceof Principal ? $principal : throw HttpException::unauthorized();
    }

    public static function establishment(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('establishment_id');

        return is_string($id) && $id !== '' ? $id : throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
    }

    public static function route(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && preg_match(self::ID, $value) === 1 ? $value : throw HttpException::notFound();
    }

    public static function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > 60) {
            throw HttpException::validation([$name => ['Valeur invalide.']]);
        }

        return $value;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->body) && $this->body[$field] !== null;
    }

    public function raw(string $field): mixed
    {
        return $this->body[$field] ?? null;
    }

    public function id(string $field, bool $required = false): ?string
    {
        return $this->pattern($field, self::ID, 'Identifiant attendu (1 à 40 caractères).', $required);
    }

    public function pattern(string $field, string $regex, string $message, bool $required = false): ?string
    {
        $value = $this->body[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field][] = 'Champ obligatoire.';
            }

            return null;
        }
        if (!is_string($value) || preg_match($regex, $value) !== 1) {
            $this->errors[$field][] = $message;

            return null;
        }

        return $value;
    }

    public function text(string $field, int $max, bool $required = false): ?string
    {
        $value = $this->body[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                $this->errors[$field][] = 'Champ obligatoire.';
            }

            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $max) {
            $this->errors[$field][] = sprintf('Texte de 1 à %d caractères attendu.', $max);

            return null;
        }

        return trim($value);
    }

    public function int(string $field, int $min, int $max, bool $required = false): ?int
    {
        $value = $this->body[$field] ?? null;
        if ($value === null) {
            if ($required) {
                $this->errors[$field][] = 'Champ obligatoire.';
            }

            return null;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            $this->errors[$field][] = sprintf('Entier de %d à %d attendu.', $min, $max);

            return null;
        }

        return $value;
    }

    /** @param list<string> $values */
    public function enum(string $field, array $values, bool $required = false): ?string
    {
        $value = $this->body[$field] ?? null;
        if ($value === null) {
            if ($required) {
                $this->errors[$field][] = 'Champ obligatoire.';
            }

            return null;
        }
        if (!is_string($value) || !in_array($value, $values, true)) {
            $this->errors[$field][] = 'Valeurs possibles : ' . implode(', ', $values) . '.';

            return null;
        }

        return $value;
    }

    public function fail(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /** @throws HttpException 422 si une erreur a été relevée */
    public function done(): void
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }
}
