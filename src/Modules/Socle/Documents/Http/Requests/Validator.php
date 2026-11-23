<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/** Validation d'un corps JSON : champs connus uniquement (aucune affectation en masse), messages en français. */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    public function __construct(private readonly array $data, array $allowed)
    {
        foreach (array_keys($data) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $this->errors[(string) $key] = ['Champ non autorisé.'];
            }
        }
    }

    /** @return array<string, mixed> */
    public static function body(ServerRequestInterface $request, bool $allowEmpty = false): array
    {
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            if ($allowEmpty) {
                return [];
            }
            throw HttpException::badRequest('Le corps de la requête est vide : un objet JSON est attendu.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return $data;
    }

    public function string(string $key, int $max = 160, bool $required = false, int $min = 1): ?string
    {
        if (!array_key_exists($key, $this->data) || $this->data[$key] === null) {
            if ($required) {
                $this->errors[$key] = ['Ce champ est obligatoire.'];
            }

            return null;
        }
        $value = $this->data[$key];
        if (!is_string($value)) {
            $this->errors[$key] = ['Ce champ doit être un texte.'];

            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) < $min || mb_strlen($value) > $max) {
            $this->errors[$key] = [sprintf('Ce champ doit compter de %d à %d caractères.', $min, $max)];

            return null;
        }

        return $value;
    }

    /** @param list<string> $allowed */
    public function enum(string $key, array $allowed, bool $required = false): ?string
    {
        $value = $this->string($key, 40, $required);
        if ($value !== null && !in_array($value, $allowed, true)) {
            $this->errors[$key] = ['Valeur non reconnue. Valeurs possibles : ' . implode(', ', $allowed) . '.'];

            return null;
        }

        return $value;
    }

    public function pattern(string $key, string $regex, string $message, bool $required = false, int $max = 80): ?string
    {
        $value = $this->string($key, $max, $required);
        if ($value !== null && preg_match($regex, $value) !== 1) {
            $this->errors[$key] = [$message];

            return null;
        }

        return $value;
    }

    public function bool(string $key): ?bool
    {
        if (!array_key_exists($key, $this->data) || $this->data[$key] === null) {
            return null;
        }
        if (!is_bool($this->data[$key])) {
            $this->errors[$key] = ['Ce champ doit être un booléen.'];

            return null;
        }

        return $this->data[$key];
    }

    /** @return list<string>|null */
    public function stringList(string $key, int $maxItems = 50, int $maxLength = 60): ?array
    {
        if (!array_key_exists($key, $this->data) || $this->data[$key] === null) {
            return null;
        }
        $value = $this->data[$key];
        if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
            $this->errors[$key] = [sprintf('Ce champ doit être une liste de %d éléments au plus.', $maxItems)];

            return null;
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || mb_strlen($item) > $maxLength) {
                $this->errors[$key] = ['Chaque élément doit être un texte court.'];

                return null;
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return array<string, scalar|null> objet de valeurs simples (variables d'un modèle) */
    public function values(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->errors[$key] = ['Ce champ doit être un objet de valeurs simples.'];

            return [];
        }
        $clean = [];
        foreach ($value as $name => $item) {
            if ($item !== null && !is_scalar($item)) {
                $this->errors[$key . '.' . $name] = ['Valeur simple attendue.'];
                continue;
            }
            $clean[(string) $name] = $item;
        }

        return $clean;
    }

    /** @return mixed valeur brute, pour les structures que l'appelant valide lui-même */
    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function fail(string $key, string $message): void
    {
        $this->errors[$key] = [$message];
    }

    /** @throws HttpException 422 s'il y a des erreurs */
    public function done(): void
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }
}
