<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/** Règle de validation d'un champ de requête. Chaque règle renvoie la valeur nettoyée ou lève un message en français. */
final class Field
{
    /** @param Closure(mixed): mixed $parser */
    private function __construct(
        private readonly Closure $parser,
        private readonly bool $required = false,
        private readonly bool $nullable = false,
    ) {
    }

    public static function string(int $max = 160, int $min = 1): self
    {
        return new self(static function (mixed $value) use ($max, $min): string {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Ce champ doit être un texte.');
            }
            $value = trim($value);
            $length = mb_strlen($value);
            if ($length < $min) {
                throw new InvalidArgumentException('Ce champ ne peut pas être vide.');
            }
            if ($length > $max) {
                throw new InvalidArgumentException(sprintf('Ce champ ne peut pas dépasser %d caractères.', $max));
            }

            return $value;
        });
    }

    public static function int(?int $min = null, ?int $max = null): self
    {
        return new self(static function (mixed $value) use ($min, $max): int {
            if (!is_int($value)) {
                throw new InvalidArgumentException('Ce champ doit être un nombre entier.');
            }
            if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
                throw new InvalidArgumentException(sprintf('Ce champ doit être compris entre %d et %d.', $min ?? PHP_INT_MIN, $max ?? PHP_INT_MAX));
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

    /** Date au format AAAA-MM-JJ, réellement existante. */
    public static function date(): self
    {
        return new self(static function (mixed $value): string {
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('La date doit être au format AAAA-MM-JJ.');
            }

            return $value;
        });
    }

    /** Identifiant d'une autre ressource (ULID ou identifiant simple) : son existence est vérifiée par le contrôleur, dans le périmètre du client. */
    public static function reference(): self
    {
        return new self(static function (mixed $value): string {
            if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) !== 1) {
                throw new InvalidArgumentException('Identifiant invalide.');
            }

            return $value;
        });
    }

    /** @return self liste de textes */
    public static function stringList(int $maxItems, int $maxLength): self
    {
        return new self(static function (mixed $value) use ($maxItems, $maxLength): array {
            if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
                throw new InvalidArgumentException(sprintf('Ce champ doit être une liste de %d éléments au plus.', $maxItems));
            }
            $items = [];
            foreach ($value as $item) {
                $items[] = self::string($maxLength)->parse($item);
            }

            return $items;
        });
    }

    /** Valeur JSON quelconque (objet, liste, texte, nombre, booléen). */
    public static function any(): self
    {
        return new self(static fn (mixed $value): mixed => $value);
    }

    /** @param Closure(mixed): mixed $parser lève InvalidArgumentException avec un message en français */
    public static function custom(Closure $parser): self
    {
        return new self($parser);
    }

    public static function forbidden(string $message): self
    {
        return new self(static function () use ($message): never {
            throw new InvalidArgumentException($message);
        });
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
