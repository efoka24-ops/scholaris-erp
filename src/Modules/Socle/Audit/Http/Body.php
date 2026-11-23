<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/** Validation d'un corps JSON : champs connus uniquement, messages en français. */
final class Body
{
    /** @var array<string, mixed> */
    private array $data;
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param list<string> $allowed */
    public function __construct(ServerRequestInterface $request, array $allowed, bool $allowEmpty = false)
    {
        $this->data = self::decode($request, $allowEmpty);
        foreach (array_keys($this->data) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $this->errors[(string) $key] = ['Champ non autorisé.'];
            }
        }
    }

    /** @return array<string, mixed> */
    public static function decode(ServerRequestInterface $request, bool $allowEmpty = false): array
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

    public function string(string $key, int $max, bool $required = false): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            if ($required) {
                $this->errors[$key] = ['Ce champ est obligatoire.'];
            }

            return null;
        }
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
            $this->errors[$key] = [sprintf('Ce champ doit être un texte de 1 à %d caractères.', $max)];

            return null;
        }

        return trim($value);
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

    public function pattern(string $key, string $regex, string $message, bool $required = false): ?string
    {
        $value = $this->string($key, 60, $required);
        if ($value !== null && preg_match($regex, $value) !== 1) {
            $this->errors[$key] = [$message];

            return null;
        }

        return $value;
    }

    public function done(): void
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }
}
