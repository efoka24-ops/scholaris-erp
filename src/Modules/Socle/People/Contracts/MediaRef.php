<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

use InvalidArgumentException;

/**
 * Référence opaque d'un média déposé dans MediaStore : à conserver telle quelle (80 caractères au plus, elle tient dans une
 * colonne `VARCHAR(80)`) et à ne jamais analyser. Elle ne contient ni le client ni un chemin de fichier ; le client est
 * toujours fourni par l'appelant, si bien qu'une référence ne désigne jamais le média d'un autre client.
 */
final class MediaRef
{
    private const PATTERN = '/^([a-z][a-z0-9-]{1,19})\.([A-Za-z0-9_-]{1,26})\.([0-9A-Z]{26})\.(pdf|jpg|png)$/D';

    public readonly string $value;

    /** @throws InvalidArgumentException référence mal formée */
    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Référence de média invalide.');
        }
        $this->value = $value;
    }

    /** Référence reçue d'un client (adresse, formulaire) : null si elle est mal formée. */
    public static function tryFrom(string $value): ?self
    {
        return preg_match(self::PATTERN, $value) === 1 ? new self($value) : null;
    }

    /** @internal réservé aux implémentations de MediaStore */
    public static function compose(string $namespace, string $ownerId, string $fileName): self
    {
        return new self($namespace . '.' . $ownerId . '.' . $fileName);
    }

    /**
     * @internal réservé aux implémentations de MediaStore
     *
     * @return array{0: string, 1: string, 2: string} espace de noms, propriétaire, nom de fichier (`<ulid>.<ext>`)
     */
    public function segments(): array
    {
        $parts = explode('.', $this->value);

        return [$parts[0], $parts[1], $parts[2] . '.' . $parts[3]];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
