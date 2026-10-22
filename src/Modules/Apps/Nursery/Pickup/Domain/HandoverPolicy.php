<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Domain;

use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo;

/**
 * Règle unique de remise d'un enfant (RG-MAT-2, RG-MAT-3), sans accès aux données ni valeur chiffrée : l'enfant n'est remis qu'à
 * une personne désignée par l'IDENTIFIANT d'une autorisation valable ce jour-là pour CET enfant, qui n'est visée par aucune
 * restriction de garde. Aucun nom libre ne remet l'enfant. Toute autre situation est un refus avec sa raison.
 */
final class HandoverPolicy
{
    public const HANDED_OVER = 'handed_over';
    public const REFUSED = 'refused';
    public const UNAUTHORIZED = 'unauthorized';
    public const AUTHORIZATION_NOT_VALID = 'authorization_not_valid';
    public const RESTRICTED = 'restricted';

    private function __construct()
    {
    }

    /**
     * @param ?AuthorizedPickupInfo $pickup autorisation désignée (null si l'identifiant est inconnu de l'établissement)
     * @param bool $restricted une restriction de garde active vise cette personne ce jour-là
     * @return array{outcome: string, reason: string}
     */
    public static function decide(?AuthorizedPickupInfo $pickup, string $learnerId, string $day, bool $restricted): array
    {
        if ($pickup === null || $pickup->learnerId !== $learnerId) {
            return ['outcome' => self::REFUSED, 'reason' => self::UNAUTHORIZED];
        }
        if (!$pickup->isValidOn($day)) {
            return ['outcome' => self::REFUSED, 'reason' => self::AUTHORIZATION_NOT_VALID];
        }
        if ($restricted) {
            return ['outcome' => self::REFUSED, 'reason' => self::RESTRICTED];
        }

        return ['outcome' => self::HANDED_OVER, 'reason' => 'authorized'];
    }
}
