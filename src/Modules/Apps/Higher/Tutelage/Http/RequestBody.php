<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/**
 * Lecture d'un corps JSON : chaque règle rend la valeur nettoyée ou range un message ; `done()` lève 422 avec toutes les erreurs
 * et refuse les champs inconnus (liste blanche = champs lus).
 */
final class RequestBody
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $known = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public static function of(ServerRequestInterface $request): self
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            return new self([]);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return new self($data);
    }

    public function has(string $field): bool
    {
        $this->known[$field] = true;

        return array_key_exists($field, $this->data);
    }

    public function string(string $field, int $max = 160, bool $required = true): ?string
    {
        $value = $this->raw($field, $required);
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
            $this->error($field, sprintf('Texte non vide de %d caractères au plus attendu.', $max));

            return null;
        }

        return trim($value);
    }

    /** @param list<string> $allowed */
    public function choice(string $field, array $allowed, bool $required = true): ?string
    {
        $value = $this->raw($field, $required);
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->error($field, 'Valeur attendue parmi : ' . implode(', ', $allowed) . '.');

            return null;
        }

        return $value;
    }

    public function int(string $field, int $min, int $max, bool $required = true): ?int
    {
        $value = $this->raw($field, $required);
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            $this->error($field, sprintf('Entier entre %d et %d attendu.', $min, $max));

            return null;
        }

        return $value;
    }

    public function date(string $field, bool $required = true): ?string
    {
        $value = $this->raw($field, $required);
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1
            || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            $this->error($field, 'Date AAAA-MM-JJ attendue.');

            return null;
        }

        return $value;
    }

    /** @return list<mixed>|null */
    public function list(string $field, bool $required = true, int $max = 50): ?array
    {
        $value = $this->raw($field, $required);
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            $this->error($field, sprintf('Liste de %d éléments au plus attendue.', $max));

            return null;
        }

        return $value;
    }

    /** @return list<string>|null */
    public function strings(string $field, bool $required = true, int $max = 50): ?array
    {
        $list = $this->list($field, $required, $max);
        if ($list === null) {
            return null;
        }
        $strings = [];
        foreach ($list as $item) {
            if (!is_string($item) || trim($item) === '' || mb_strlen($item) > 80) {
                $this->error($field, 'Liste de textes courts attendue.');

                return null;
            }
            $strings[trim($item)] = true;
        }

        return array_map('strval', array_keys($strings));
    }

    public function error(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function done(): void
    {
        foreach (array_keys($this->data) as $field) {
            if (!isset($this->known[(string) $field])) {
                $this->errors[(string) $field][] = 'Champ inconnu.';
            }
        }
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }

    private function raw(string $field, bool $required): mixed
    {
        $this->known[$field] = true;
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null) {
            if ($required) {
                $this->errors[$field][] = 'Champ obligatoire.';
            }

            return null;
        }

        return $this->data[$field];
    }
}
