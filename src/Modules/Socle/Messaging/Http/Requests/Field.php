<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Requests;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/** Règle de validation d'un champ de requête : renvoie la valeur nettoyée ou lève un message en français. */
final class Field
{
    /** @param Closure(mixed): mixed $parser */
    private function __construct(
        private readonly Closure $parser,
        private readonly bool $required = false,
        private readonly bool $nullable = false,
    ) {
    }

    public static function string(int $max = 160, int $min = 1, ?string $pattern = null): self
    {
        return new self(static function (mixed $value) use ($max, $min, $pattern): string {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Ce champ doit être un texte.');
            }
            $value = trim($value);
            if (mb_strlen($value) < $min) {
                throw new InvalidArgumentException('Ce champ ne peut pas être vide.');
            }
            if (mb_strlen($value) > $max) {
                throw new InvalidArgumentException(sprintf('Ce champ ne peut pas dépasser %d caractères.', $max));
            }
            if ($pattern !== null && preg_match($pattern, $value) !== 1) {
                throw new InvalidArgumentException('Format invalide.');
            }

            return $value;
        });
    }

    /** @param list<string> $allowed */
    public static function enum(array $allowed): self
    {
        return new self(static function (mixed $value) use ($allowed): string {
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                throw new InvalidArgumentException('Valeur non reconnue. Valeurs possibles : ' . implode(', ', $allowed) . '.');
            }

            return $value;
        });
    }

    public static function bool(): self
    {
        return new self(static function (mixed $value): bool {
            if (!is_bool($value)) {
                throw new InvalidArgumentException('Ce champ doit être vrai ou faux.');
            }

            return $value;
        });
    }

    /**
     * @param list<string> $allowed
     * @return self liste de valeurs d'une énumération
     */
    public static function enumList(array $allowed, int $maxItems = 5): self
    {
        return new self(static function (mixed $value) use ($allowed, $maxItems): array {
            if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
                throw new InvalidArgumentException(sprintf('Ce champ doit être une liste de %d éléments au plus.', $maxItems));
            }
            $items = [];
            foreach ($value as $item) {
                $items[] = self::enum($allowed)->parse($item);
            }

            return array_values(array_unique($items));
        });
    }

    /** Objet « nom => valeur simple » (variables d'un modèle). */
    public static function scalarMap(int $maxItems = 20): self
    {
        return new self(static function (mixed $value) use ($maxItems): array {
            if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > $maxItems) {
                throw new InvalidArgumentException('Ce champ doit être un objet « nom : valeur ».');
            }
            foreach ($value as $name => $item) {
                if (!is_string($name) || !is_scalar($item)) {
                    throw new InvalidArgumentException('Chaque variable doit avoir un nom et une valeur simple.');
                }
            }

            return $value;
        });
    }

    /** Date et heure ISO 8601 avec fuseau, renvoyées au format ATOM. */
    public static function dateTime(): self
    {
        return new self(static function (mixed $value): string {
            $date = is_string($value) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $value) : false;
            if ($date === false) {
                throw new InvalidArgumentException('La date doit être au format ISO 8601, ex. 2026-10-09T07:00:00+01:00.');
            }

            return $date->format(DATE_ATOM);
        });
    }

    /** @param Closure(mixed): mixed $parser lève InvalidArgumentException avec un message en français */
    public static function custom(Closure $parser): self
    {
        return new self($parser);
    }

    public function required(): self
    {
        return new self($this->parser, true, $this->nullable);
    }

    public function nullable(): self
    {
        return new self($this->parser, $this->required, true);
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /** @throws InvalidArgumentException */
    public function parse(mixed $value): mixed
    {
        if ($value === null) {
            if ($this->nullable) {
                return null;
            }
            throw new InvalidArgumentException('Ce champ ne peut pas être nul.');
        }

        return ($this->parser)($value);
    }
}
