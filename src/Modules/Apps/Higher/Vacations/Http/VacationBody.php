<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/** Lecture validée d'un corps JSON (champs connus, erreurs par champ, champ inconnu refusé). */
final class VacationBody
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    public static function of(ServerRequestInterface $request): self
    {
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            return new self([]);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return new self($data);
    }

    public function string(string $field, int $max, bool $required = true, ?string $pattern = null): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field][] = 'Champ obligatoire.';
            }

            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $max || ($pattern !== null && preg_match($pattern, trim($value)) !== 1)) {
            $this->errors[$field][] = 'Valeur invalide.';

            return null;
        }

        return trim($value);
    }

    /** @param list<string> $allowed */
    public function choice(string $field, array $allowed): ?string
    {
        $value = $this->string($field, 40);
        if ($value !== null && !in_array($value, $allowed, true)) {
            $this->errors[$field][] = 'Valeur attendue : ' . implode(', ', $allowed) . '.';

            return null;
        }

        return $value;
    }

    /** @param list<string> $known */
    public function done(array $known): void
    {
        foreach (array_diff(array_keys($this->data), $known) as $unknown) {
            $this->errors[(string) $unknown][] = 'Champ inconnu.';
        }
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }
}
