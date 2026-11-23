<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Contracts;

/**
 * Résultat d'un élément de lot : `applied`, `duplicate` (déjà appliqué), `conflict` (409, la version du serveur est rendue
 * dans `data` et n'est jamais écrasée) ou `rejected` (invalide, refusé ou sans gestionnaire).
 */
final class ItemResult
{
    public const APPLIED = 'applied';
    public const DUPLICATE = 'duplicate';
    public const CONFLICT = 'conflict';
    public const REJECTED = 'rejected';
    public const STATUSES = [self::APPLIED, self::DUPLICATE, self::CONFLICT, self::REJECTED];

    /** @param array<string, mixed> $data données rendues au client (identifiant créé, version du serveur, erreurs par champ) */
    private function __construct(
        public readonly string $status,
        public readonly int $code,
        public readonly ?string $message,
        public readonly array $data,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function applied(array $data = []): self
    {
        return new self(self::APPLIED, 200, null, $data);
    }

    /** @param array<string, mixed> $data */
    public static function duplicate(array $data = []): self
    {
        return new self(self::DUPLICATE, 200, null, $data);
    }

    /** @param array<string, mixed> $server version du serveur, rendue telle quelle au client */
    public static function conflict(string $message, array $server = []): self
    {
        return new self(self::CONFLICT, 409, $message, $server === [] ? [] : ['server' => $server]);
    }

    /** @param array<string, list<string>> $errors erreurs par champ */
    public static function rejected(string $message, int $code = 422, array $errors = []): self
    {
        return new self(self::REJECTED, $code, $message, $errors === [] ? [] : ['errors' => $errors]);
    }
}
