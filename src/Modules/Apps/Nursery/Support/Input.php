<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Support;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/**
 * Lecture et contrôle d'un corps JSON (tous les sous-modules de la Maternelle privée). Chaque règle range la valeur nettoyée ou un message en français ;
 * `done()` lève 422 avec toutes les erreurs. En mode partiel (PATCH), un champ absent est ignoré et rien n'est obligatoire.
 * Champs inconnus refusés : la liste blanche est celle des règles appelées.
 */
final class Input
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $known = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data, private readonly bool $partial = false)
    {
    }

    public static function of(ServerRequestInterface $request, bool $partial = false): self
    {
        return new self(self::body($request), $partial);
    }

    /** @return array<string, mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return $data;
    }

    public function string(string $field, int $max = 160, bool $required = true, bool $nullable = false): ?string
    {
        return $this->rule($field, $required, $nullable, static function (mixed $v) use ($max): string {
            if (!is_string($v) || trim($v) === '') {
                throw new \InvalidArgumentException('Texte non vide attendu.');
            }
            if (mb_strlen(trim($v)) > $max) {
                throw new \InvalidArgumentException(sprintf('%d caractères au plus.', $max));
            }

            return trim($v);
        });
    }

    /** Code court en majuscules (EC, CF, L1, UE1-A…). */
    public function code(string $field, int $max = 12, bool $required = true): ?string
    {
        return $this->rule($field, $required, false, static function (mixed $v) use ($max): string {
            if (!is_string($v) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', trim($v)) !== 1 || mb_strlen(trim($v)) > $max) {
                throw new \InvalidArgumentException(sprintf('Code de 1 à %d caractères (lettres, chiffres, « . », « _ », « - »).', $max));
            }

            return strtoupper(trim($v));
        });
    }

    public function int(string $field, int $min, int $max, bool $required = true, bool $nullable = false): ?int
    {
        return $this->rule($field, $required, $nullable, static function (mixed $v) use ($min, $max): int {
            if (!is_int($v) || $v < $min || $v > $max) {
                throw new \InvalidArgumentException(sprintf('Entier entre %d et %d attendu.', $min, $max));
            }

            return $v;
        });
    }

    /** Nombre au pas donné (ex. 0,25 heure, 0,01 de coefficient). */
    public function number(string $field, float $min, float $max, float $step = 0.01, bool $required = true): ?float
    {
        return $this->rule($field, $required, false, static function (mixed $v) use ($min, $max, $step): float {
            if ((!is_int($v) && !is_float($v)) || $v < $min || $v > $max) {
                throw new \InvalidArgumentException(sprintf('Nombre entre %s et %s attendu.', (string) $min, (string) $max));
            }
            $steps = $v / $step;
            if (abs($steps - round($steps)) > 1e-6) {
                throw new \InvalidArgumentException(sprintf('Valeur au pas de %s attendue.', (string) $step));
            }

            return (float) $v;
        });
    }

    public function bool(string $field, bool $required = true): ?bool
    {
        return $this->rule($field, $required, false, static function (mixed $v): bool {
            if (!is_bool($v)) {
                throw new \InvalidArgumentException('Booléen attendu.');
            }

            return $v;
        });
    }

    /** @param list<string> $allowed */
    public function enum(string $field, array $allowed, bool $required = true): ?string
    {
        return $this->rule($field, $required, false, static function (mixed $v) use ($allowed): string {
            if (!is_string($v) || !in_array($v, $allowed, true)) {
                throw new \InvalidArgumentException('Valeurs possibles : ' . implode(', ', $allowed) . '.');
            }

            return $v;
        });
    }

    public function date(string $field, bool $required = true, bool $nullable = false): ?string
    {
        return $this->rule($field, $required, $nullable, static function (mixed $v): string {
            $date = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
            if ($date === false || $date->format('Y-m-d') !== $v) {
                throw new \InvalidArgumentException('Date au format AAAA-MM-JJ attendue.');
            }

            return $v;
        });
    }

    /** Identifiant d'une autre ressource (ULID ou identifiant simple) ; son existence est vérifiée par le service. */
    public function reference(string $field, bool $required = true, bool $nullable = false): ?string
    {
        return $this->rule($field, $required, $nullable, static function (mixed $v): string {
            if (!is_string($v) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v) !== 1) {
                throw new \InvalidArgumentException('Identifiant invalide.');
            }

            return $v;
        });
    }

    /** @return list<string>|null */
    public function references(string $field, int $maxItems = 50, bool $required = true, bool $nullable = false): ?array
    {
        return $this->rule($field, $required, $nullable, static function (mixed $v) use ($maxItems): array {
            if (!is_array($v) || !array_is_list($v) || count($v) > $maxItems) {
                throw new \InvalidArgumentException(sprintf('Liste de %d identifiants au plus attendue.', $maxItems));
            }
            foreach ($v as $item) {
                if (!is_string($item) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $item) !== 1) {
                    throw new \InvalidArgumentException('Identifiant invalide dans la liste.');
                }
            }

            return array_values(array_unique($v));
        });
    }

    /**
     * Valeur libre contrôlée par l'appelant (objet de paramètres, liste de contacts…).
     *
     * @param \Closure(mixed): mixed $parser lève InvalidArgumentException
     */
    public function custom(string $field, \Closure $parser, bool $required = true): mixed
    {
        return $this->rule($field, $required, false, $parser);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    public function fail(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * Valeurs contrôlées (champs présents seulement), ou 422 avec toutes les erreurs ; tout champ non déclaré est refusé.
     *
     * @return array<string, mixed>
     */
    public function done(): array
    {
        foreach (array_keys($this->data) as $field) {
            if (!isset($this->known[(string) $field])) {
                $this->errors[(string) $field][] = 'Champ non reconnu.';
            }
        }
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }

        return $this->values;
    }

    /**
     * @template T
     * @param \Closure(mixed): T $parser
     * @return T|null
     */
    private function rule(string $field, bool $required, bool $nullable, \Closure $parser): mixed
    {
        $this->known[$field] = true;
        if (!array_key_exists($field, $this->data)) {
            if ($required && !$this->partial) {
                $this->errors[$field][] = 'Ce champ est obligatoire.';
            }

            return null;
        }
        $value = $this->data[$field];
        if ($value === null) {
            if ($nullable) {
                $this->values[$field] = null;

                return null;
            }
            $this->errors[$field][] = 'Ce champ ne peut pas être nul.';

            return null;
        }
        try {
            $parsed = $parser($value);
        } catch (\InvalidArgumentException $e) {
            $this->errors[$field][] = $e->getMessage();

            return null;
        }
        $this->values[$field] = $parsed;

        return $parsed;
    }
}
