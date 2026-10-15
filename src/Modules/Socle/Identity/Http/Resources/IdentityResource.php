<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Http\Resources;

use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\User;

/** Représentations de sortie à liste blanche de champs : jamais de hachage, de secret ni de jeton. */
final class IdentityResource
{
    /**
     * @param array{first_name: string, last_name: string}|null $person
     * @return array<string, mixed>
     */
    public static function user(User $user, ?array $person): array
    {
        return [
            'id' => $user->id,
            'person_id' => $user->personId,
            'first_name' => $person['first_name'] ?? null,
            'last_name' => $person['last_name'] ?? null,
            'locale' => $user->locale,
            'status' => $user->status,
        ];
    }

    /**
     * @param array{id: string, name: string, location: ?string, ip: ?string, last_seen_at: string, trusted_until: ?string, current: bool} $device
     * @return array<string, mixed>
     */
    public static function device(array $device): array
    {
        return [
            'id' => $device['id'],
            'name' => $device['name'],
            'location' => $device['location'],
            'last_seen_at' => $device['last_seen_at'],
            'trusted_until' => $device['trusted_until'],
            'current' => $device['current'],
        ];
    }

    /** @return array<string, mixed> */
    public static function assignment(RoleAssignment $a): array
    {
        return [
            'id' => $a->id,
            'person_id' => $a->personId,
            'role' => $a->role,
            'scope_type' => $a->scopeType->value,
            'scope_id' => $a->scopeId,
            'establishment_id' => $a->establishmentId,
            'starts_at' => $a->startsAt,
            'ends_at' => $a->endsAt,
            'delegated_by' => $a->delegatedBy,
        ];
    }
}
