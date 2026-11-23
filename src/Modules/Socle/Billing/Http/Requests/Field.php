<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Requests;

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
    ) {
    }

    public static function string(int $max = 160): self
    {
        return new self(static function (mixed $value) use ($max): string {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Ce champ doit être un texte non vide.');
            }
            $value = trim($value);
            if (mb_strlen($value) > $max) {
                throw new InvalidArgumentException(sprintf('Ce champ ne peut pas dépasser %d caractères.', $max));
            }

            return $value;
        });
    }

    /** Montant ou nombre entier strict : « 1000.5 » et « 1000.0 » sont refusés (XAF sans décimales). */
    public static function int(int $min = 0, ?int $max = null): self
    {
        return new self(static function (mixed $value) use ($min, $max): int {
            if (!is_int($value)) {
                throw new InvalidArgumentException('Ce champ doit être un nombre entier (XAF, sans décimales).');
            }
            if ($value < $min || ($max !== null && $value > $max)) {
                throw new InvalidArgumentException(sprintf('Ce champ doit être compris entre %d et %s.', $min, $max === null ? 'la limite autorisée' : (string) $max));
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

    /** Identifiant d'une autre ressource : son existence est vérifiée dans le périmètre du client par le service. */
    public static function id(): self
    {
        return new self(static function (mixed $value): string {
            if (!is_string($value) || preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $value) !== 1) {
                throw new InvalidArgumentException('Identifiant invalide.');
            }

            return $value;
        });
    }

    /** @param Closure(mixed): mixed $parser */
    public static function custom(Closure $parser): self
    {
        return new self($parser);
    }

    public function required(): self
    {
        return new self($this->parser, true);
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function parse(mixed $value): mixed
    {
        return ($this->parser)($value);
    }
}
