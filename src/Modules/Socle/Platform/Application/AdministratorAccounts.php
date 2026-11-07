<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;
use Scholaris\Modules\Socle\Identity\Contracts\ClientAdministrators;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformStaff;
use Scholaris\Modules\Socle\Platform\Domain\PlatformPermissions;

/**
 * Administrateurs gérés par la plateforme : super administrateurs d'un client et administrateurs de plateforme.
 * Invitation : compte en attente, message d'invitation par e-mail (le SMS n'est pas encore branché : un administrateur
 * invité par téléphone seul active son compte depuis l'écran d'activation, code reçu par SMS dès que le fournisseur
 * est configuré). Règles : un administrateur de plateforme ne se désactive jamais lui-même, ni le dernier actif.
 * Membres de la plateforme (C-112) : chacun a un rôle (administrateur, support, finance, audit) attribué par un administrateur ;
 * on ne change jamais son propre rôle et le dernier administrateur actif garde le sien.
 */
final class AdministratorAccounts
{
    public const CLIENT_ROLE = 'super_admin';
    public const PLATFORM_ROLE = 'platform_admin';

    public function __construct(
        private readonly AccountAdministration $accounts,
        private readonly ClientAdministrators $clientAdministrators,
        private readonly PlatformNotifier $notifier,
        private readonly PlatformAudit $audit,
        private readonly Config $config,
        private readonly PlatformStaff $staff,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId): array
    {
        if ($tenantId === PlatformAudit::PLATFORM_TENANT) {
            return array_map(static fn (array $member): array => self::present($member) + ['role' => $member['role']], $this->staff->members());
        }

        return array_map(self::present(...), $this->accounts->holders($tenantId, self::CLIENT_ROLE));
    }

    /**
     * Attribue un rôle de plateforme à un membre (écran Administrateurs).
     *
     * @return array<string, mixed>
     */
    public function setRole(string $userId, string $role, string $actorId, ?string $ip): array
    {
        if (!in_array($role, PlatformPermissions::ROLES, true)) {
            throw HttpException::validation(['role' => ['Rôle de plateforme inconnu.']]);
        }
        $current = $this->memberRole($userId);
        if ($userId === $actorId) {
            throw HttpException::conflict('Vous ne pouvez pas changer votre propre rôle.');
        }
        if ($current === self::PLATFORM_ROLE && $role !== self::PLATFORM_ROLE) {
            $this->assertAnotherActiveAdministrator($userId);
        }
        $this->staff->assign($userId, $role, $actorId);
        $this->audit->record(null, $actorId, 'platform.administrator_role_changed', 'user', $userId, ['before' => $current, 'after' => $role], $ip);

        return self::present($this->accounts->holder(PlatformAudit::PLATFORM_TENANT, $role, $userId)) + ['role' => $role];
    }

    /**
     * @param array{first_name: string, last_name: string, phone: ?string, email: ?string, locale: string} $person
     * @return array<string, mixed>
     */
    public function invite(string $tenantId, string $tenantName, array $person, string $actorId, ?string $ip, string $platformRole = self::PLATFORM_ROLE): array
    {
        $platform = $tenantId === PlatformAudit::PLATFORM_TENANT;
        $ids = $platform
            ? $this->accounts->invitePlatformAdministrator($person['first_name'], $person['last_name'], $person['phone'], $person['email'], $person['locale'], $actorId)
            : $this->clientAdministrators->invite($tenantId, $person['first_name'], $person['last_name'], $person['phone'], $person['email'], $person['locale'], $actorId);
        $role = $platform ? $platformRole : self::CLIENT_ROLE;
        if ($platform && $role !== self::PLATFORM_ROLE) {
            $this->staff->assign($ids['user_id'], $role, $actorId);
        }
        $holder = $this->accounts->holder($tenantId, $role, $ids['user_id']);
        $delivery = $this->sendInvitation($holder, $tenantName);
        $this->audit->record($platform ? null : $tenantId, $actorId, 'platform.administrator_invited', 'user', $ids['user_id'], ['role' => $role, 'delivery' => $delivery], $ip);

        return self::present($holder) + ($platform ? ['role' => $role] : []) + ['invitation' => $delivery];
    }

    /** @return array<string, mixed> */
    public function resendInvitation(string $tenantId, string $tenantName, string $userId, string $actorId, ?string $ip): array
    {
        $holder = $this->accounts->holder($tenantId, $this->roleIn($tenantId, $userId), $userId);
        if ($holder['status'] !== 'pending') {
            throw HttpException::conflict('Ce compte est déjà activé ou bloqué : l\'invitation ne s\'applique qu\'à un compte en attente.');
        }
        $delivery = $this->sendInvitation($holder, $tenantName);
        $this->audit->record($tenantId === PlatformAudit::PLATFORM_TENANT ? null : $tenantId, $actorId, 'platform.administrator_invitation_resent', 'user', $userId, ['delivery' => $delivery], $ip);

        return self::present($holder) + ['invitation' => $delivery];
    }

    /** @return array<string, mixed> */
    public function setBlocked(string $tenantId, string $userId, bool $blocked, string $actorId, ?string $ip): array
    {
        $role = $this->roleIn($tenantId, $userId);
        if ($blocked && $tenantId === PlatformAudit::PLATFORM_TENANT) {
            $this->assertCanDeactivate($userId, $actorId, $role);
        }
        $this->accounts->setBlocked($tenantId, $role, $userId, $blocked, $actorId);
        $this->audit->record($tenantId === PlatformAudit::PLATFORM_TENANT ? null : $tenantId, $actorId, $blocked ? 'platform.administrator_blocked' : 'platform.administrator_unblocked', 'user', $userId, ['role' => $role], $ip);

        return self::present($this->accounts->holder($tenantId, $role, $userId));
    }

    /** @return array<string, mixed> */
    public function resetAccess(string $tenantId, string $tenantName, string $userId, string $actorId, ?string $ip): array
    {
        $role = $this->roleIn($tenantId, $userId);
        if ($tenantId === PlatformAudit::PLATFORM_TENANT && $userId === $actorId) {
            throw HttpException::conflict('Vous ne pouvez pas réinitialiser votre propre accès.');
        }
        $this->accounts->resetAccess($tenantId, $role, $userId, $actorId);
        $holder = $this->accounts->holder($tenantId, $role, $userId);
        $delivery = $this->sendInvitation($holder, $tenantName);
        $this->audit->record($tenantId === PlatformAudit::PLATFORM_TENANT ? null : $tenantId, $actorId, 'platform.administrator_access_reset', 'user', $userId, ['role' => $role, 'delivery' => $delivery], $ip);

        return self::present($holder) + ['invitation' => $delivery];
    }

    private function assertCanDeactivate(string $userId, string $actorId, string $role): void
    {
        if ($userId === $actorId) {
            throw HttpException::conflict('Vous ne pouvez pas désactiver votre propre compte.');
        }
        if ($role === self::PLATFORM_ROLE) {
            $this->assertAnotherActiveAdministrator($userId);
        }
    }

    private function assertAnotherActiveAdministrator(string $userId): void
    {
        $others = array_filter(
            $this->accounts->holders(PlatformAudit::PLATFORM_TENANT, self::PLATFORM_ROLE),
            static fn (array $holder): bool => $holder['user_id'] !== $userId && $holder['status'] === 'active',
        );
        if ($others === []) {
            throw HttpException::conflict('Impossible de désactiver le dernier administrateur de plateforme actif.');
        }
    }

    /** @param array<string, mixed> $holder */
    private function sendInvitation(array $holder, string $tenantName): string
    {
        $login = (string) ($holder['email'] ?? $holder['phone'] ?? '');

        return $this->notifier->send('platform_admin_invitation', (string) $holder['locale'], $holder['email'], $holder['phone'], [
            'first_name' => (string) $holder['first_name'],
            'establishment_name' => $tenantName,
            'reference' => $login,
            'link' => rtrim((string) $this->config->get('app.url', ''), '/') . '/activation',
        ]);
    }

    /** Rôle d'administration du compte : super_admin dans un client, son rôle de plateforme sur la plateforme (404 sinon). */
    private function roleIn(string $tenantId, string $userId): string
    {
        return $tenantId === PlatformAudit::PLATFORM_TENANT ? $this->memberRole($userId) : self::CLIENT_ROLE;
    }

    private function memberRole(string $userId): string
    {
        return $this->staff->roleOf($userId) ?? throw HttpException::notFound('Administrateur introuvable.');
    }

    /**
     * @param array<string, mixed> $holder
     * @return array<string, mixed>
     */
    private static function present(array $holder): array
    {
        return [
            'id' => (string) $holder['user_id'],
            'person_id' => (string) $holder['person_id'],
            'first_name' => (string) $holder['first_name'],
            'last_name' => (string) $holder['last_name'],
            'email' => $holder['email'],
            'phone' => $holder['phone'],
            'status' => (string) $holder['status'],
            'two_factor_enabled' => (bool) $holder['two_factor_enabled'],
            'last_login_at' => $holder['last_login_at'],
            'created_at' => (string) $holder['created_at'],
        ];
    }
}
