<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;

/** Doublure des comptes d'administration pour la boîte du support : détenteurs déclarés par client et par rôle. */
final class SupportAccountsStub implements AccountAdministration
{
    /** @var array<string, list<array{user_id: string, person_id: string, first_name: string, last_name: string, phone: ?string, email: ?string, locale: string, status: string, two_factor_enabled: bool, last_login_at: ?string, created_at: string}>> */
    private array $holders = [];

    public function add(string $tenantId, string $role, string $userId, string $personId, string $status = 'active'): void
    {
        $this->holders[$tenantId . '|' . $role][] = [
            'user_id' => $userId, 'person_id' => $personId, 'first_name' => ucfirst($userId), 'last_name' => 'Test', 'phone' => null, 'email' => null,
            'locale' => 'fr', 'status' => $status, 'two_factor_enabled' => true, 'last_login_at' => null, 'created_at' => '2026-10-01T00:00:00+01:00',
        ];
    }

    public function holders(string $tenantId, string $role): array
    {
        if (!in_array($role, ['super_admin', 'platform_admin'], true)) {
            throw HttpException::notFound();
        }

        return $this->holders[$tenantId . '|' . $role] ?? [];
    }

    public function holder(string $tenantId, string $role, string $userId): array
    {
        throw HttpException::notFound();
    }

    public function invitePlatformAdministrator(string $firstName, string $lastName, ?string $phone, ?string $email, string $locale, string $actorId): array
    {
        throw HttpException::notFound();
    }

    public function setBlocked(string $tenantId, string $role, string $userId, bool $blocked, string $actorId): void
    {
    }

    public function resetAccess(string $tenantId, string $role, string $userId, string $actorId): void
    {
    }

    public function users(string $tenantId): array
    {
        return [];
    }

    public function issueAccessCode(string $tenantId, string $userId, string $actorId, ?int $ttlSeconds = null): array
    {
        throw HttpException::notFound();
    }

    public function isOpenAccessCode(string $tenantId, string $userId, string $code): bool
    {
        return false;
    }

    public function createWithProvisionalPassword(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email, string $locale, string $actorId, ?string $personId = null): array
    {
        throw HttpException::notFound();
    }

    public function reissueProvisionalPassword(string $tenantId, string $userId, string $actorId, ?string $establishmentId = null, bool $resetTwoFactor = false): array
    {
        throw HttpException::notFound();
    }
}
