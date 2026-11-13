<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Contracts\RoleAssignmentGateway;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/** Façade de RoleAssignmentService pour les autres modules : mêmes règles que la route, aucune règle propre. */
final class RoleAssignmentGatewayService implements RoleAssignmentGateway
{
    public function __construct(
        private readonly RoleAssignmentService $assignments,
        private readonly PdoUserRepository $users,
    ) {
    }

    public function assignableRoles(string $tenantId, string $actorUserId, string $establishmentId): array
    {
        $actor = $this->users->find($tenantId, $actorUserId);
        if ($actor === null) {
            return [];
        }

        return array_map(static fn ($role): array => [
            'code' => $role->code,
            'level' => $role->level,
            'sensitive' => $role->sensitive,
            'label_fr' => $role->labelFr,
            'label_en' => $role->labelEn,
        ], $this->assignments->assignableRoles($actor, $establishmentId));
    }

    public function identifiersInUse(string $tenantId, array $phones, array $emails): array
    {
        return [
            'phones' => array_values(array_filter($phones, fn (string $phone): bool => $this->users->phoneTaken($tenantId, $phone))),
            'emails' => array_values(array_filter($emails, fn (string $email): bool => $this->users->emailTaken($tenantId, $email))),
        ];
    }

    public function assign(string $tenantId, string $actorUserId, string $personId, string $role, string $scopeType, ?string $scopeId, ?string $establishmentId, ?string $startsAt, ?string $endsAt): string
    {
        $actor = $this->users->find($tenantId, $actorUserId) ?? throw HttpException::forbidden();
        $this->assignments->assertMayAssign($actor);

        return $this->assignments->assign($actor, [
            'person_id' => $personId,
            'role' => $role,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'establishment_id' => $establishmentId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ])->id;
    }
}
