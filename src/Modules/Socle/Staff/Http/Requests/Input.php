<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Requests;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/**
 * Validation des corps de requête : tout champ inconnu est refusé (aucune affectation en masse, tenant_id et
 * identifiants imposés ne viennent jamais du client). Chaque règle renvoie la valeur nettoyée ou lève un message en français.
 */
final class Input
{
    /** @return array<string, mixed> corps JSON de la requête */
    public static function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            throw HttpException::badRequest('Le corps de la requête est vide : un objet JSON est attendu.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, Closure(mixed): mixed> $rules
     * @param list<string> $required
     * @param list<string> $nullable
     * @return array<string, mixed>
     */
    public static function validate(array $data, array $rules, array $required = [], array $nullable = [], bool $partial = false): array
    {
        $errors = [];
        $values = [];
        foreach (array_keys($data) as $name) {
            if (!isset($rules[$name])) {
                $errors[(string) $name] = ['Champ non autorisé.'];
            }
        }
        foreach ($rules as $name => $rule) {
            if (!array_key_exists($name, $data)) {
                if (!$partial && in_array($name, $required, true)) {
                    $errors[$name] = ['Ce champ est obligatoire.'];
                }
                continue;
            }
            if ($data[$name] === null && in_array($name, $nullable, true)) {
                $values[$name] = null;
                continue;
            }
            try {
                $values[$name] = $rule($data[$name]);
            } catch (InvalidArgumentException $e) {
                $errors[$name] = [$e->getMessage()];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $values;
    }

    /** @return Closure(mixed): string */
    public static function text(int $max = 160): Closure
    {
        return static function (mixed $value) use ($max): string {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Texte non vide attendu.');
            }
            $value = trim($value);
            if (mb_strlen($value) > $max) {
                throw new InvalidArgumentException(sprintf('Ce champ ne peut pas dépasser %d caractères.', $max));
            }

            return $value;
        };
    }

    /**
     * @param list<string> $allowed
     * @return Closure(mixed): string
     */
    public static function enum(array $allowed): Closure
    {
        return static function (mixed $value) use ($allowed): string {
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                throw new InvalidArgumentException('Valeur non reconnue. Valeurs possibles : ' . implode(', ', $allowed) . '.');
            }

            return $value;
        };
    }

    /** @return Closure(mixed): string */
    public static function ref(): Closure
    {
        return static function (mixed $value): string {
            if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) !== 1) {
                throw new InvalidArgumentException('Identifiant invalide.');
            }

            return $value;
        };
    }

    /** @return Closure(mixed): string */
    public static function date(): Closure
    {
        return static function (mixed $value): string {
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('La date doit être au format AAAA-MM-JJ.');
            }

            return $value;
        };
    }

    /** @return Closure(mixed): bool */
    public static function bool(): Closure
    {
        return static function (mixed $value): bool {
            if (!is_bool($value)) {
                throw new InvalidArgumentException('Booléen attendu (true ou false).');
            }

            return $value;
        };
    }
    /** @return Closure(mixed): int */
    public static function int(int $min = 0, int $max = 100000000): Closure
    {
        return static function (mixed $value) use ($min, $max): int {
            if (!is_int($value) || $value < $min || $value > $max) {
                throw new InvalidArgumentException(sprintf('Entier attendu, entre %d et %d.', $min, $max));
            }

            return $value;
        };
    }

    /** @return Closure(mixed): string mois « AAAA-MM » */
    public static function month(): Closure
    {
        return static function (mixed $value): string {
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value . '-01') : false;
            if ($date === false || $date->format('Y-m') !== $value) {
                throw new InvalidArgumentException('La période doit être au format AAAA-MM.');
            }

            return $value;
        };
    }
}
