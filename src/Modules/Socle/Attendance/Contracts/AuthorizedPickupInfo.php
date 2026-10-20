<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/**
 * Personne autorisée à récupérer un enfant (table `authorized_pickups`). La remise désigne toujours l'autorisation par son
 * identifiant `id`, jamais par le nom : `name` ne sert qu'à l'affichage.
 */
final class AuthorizedPickupInfo
{
    public const PERMANENT = 'permanent';
    public const DAY = 'day';
    public const ACTIVE = 'active';
    public const REVOKED = 'revoked';

    public function __construct(
        public readonly string $id,
        public readonly string $learnerId,
        public readonly string $name,
        public readonly string $relationship,
        public readonly ?string $phone,
        public readonly ?string $photoRef,
        public readonly string $grantType,
        public readonly ?string $validOn,
        public readonly string $status,
    ) {
    }

    /** Valable à cette date (AAAA-MM-JJ) : active, et permanente ou accordée pour ce jour exactement. Une révoquée ne l'est jamais. */
    public function isValidOn(string $date): bool
    {
        return $this->status === self::ACTIVE && ($this->grantType === self::PERMANENT || ($this->grantType === self::DAY && $this->validOn === $date));
    }
}
