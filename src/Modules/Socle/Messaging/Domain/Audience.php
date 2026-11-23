<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Domain;

use InvalidArgumentException;

/**
 * Cible d'un message : une personne, une classe, un niveau, l'établissement actif, les impayés ou les absents du jour.
 * Les types « personne », « classe » et « niveau » portent un identifiant ; les autres n'en portent pas.
 */
final class Audience
{
    public const PERSON = 'person';
    public const STRUCTURE = 'structure';
    public const LEVEL = 'level';
    public const ESTABLISHMENT = 'establishment';
    public const ARREARS = 'arrears';
    public const ABSENT_TODAY = 'absent_today';

    private function __construct(
        public readonly string $type,
        public readonly ?string $id,
    ) {
    }

    /** @return list<string> */
    public static function types(): array
    {
        return [self::PERSON, self::STRUCTURE, self::LEVEL, self::ESTABLISHMENT, self::ARREARS, self::ABSENT_TODAY];
    }

    public static function of(string $type, ?string $id = null): self
    {
        if (!in_array($type, self::types(), true)) {
            throw new InvalidArgumentException('Type d\'audience inconnu. Valeurs possibles : ' . implode(', ', self::types()) . '.');
        }
        $needsId = in_array($type, [self::PERSON, self::STRUCTURE, self::LEVEL], true);
        if ($needsId && ($id === null || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) !== 1)) {
            throw new InvalidArgumentException('Cette audience exige un identifiant valide.');
        }
        if (!$needsId && $id !== null) {
            throw new InvalidArgumentException('Cette audience ne prend pas d\'identifiant.');
        }

        return new self($type, $id);
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $extra = array_diff(array_keys($data), ['type', 'id']);
        $type = $data['type'] ?? null;
        $id = $data['id'] ?? null;
        if ($extra !== [] || !is_string($type) || ($id !== null && !is_string($id))) {
            throw new InvalidArgumentException('Audience invalide : {"type": ..., "id": ...} attendu.');
        }

        return self::of($type, $id);
    }

    /** @return array{type: string, id: string|null} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
