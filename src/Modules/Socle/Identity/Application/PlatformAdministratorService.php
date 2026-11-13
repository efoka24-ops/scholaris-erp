<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Domain\Role;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleAssignmentRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/**
 * Crée un administrateur de plateforme : personne et compte dans le client technique `platform`, mot de passe généré
 * (16 caractères, jamais passé en argument ni stocké en clair), rôle global `platform_admin`. La double authentification
 * est exigée dès la première connexion, quel que soit le réglage `app.two_factor_required`.
 */
final class PlatformAdministratorService implements PlatformAdministrators
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public function __construct(
        private readonly AccountProvisioner $provisioner,
        private readonly PdoUserRepository $users,
        private readonly PdoRoleAssignmentRepository $assignments,
        private readonly PasswordHasher $hasher,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    public function create(string $identifier, string $firstName, string $lastName): array
    {
        $parsed = Identifier::fromInput($identifier);
        if (!$parsed->valid) {
            throw HttpException::validation(['identifier' => ['Indiquez une adresse e-mail ou un numéro de téléphone valide.']]);
        }

        $tenant = Role::PLATFORM_TENANT;
        $ids = $this->provisioner->provision(
            $tenant,
            $firstName,
            $lastName,
            $parsed->isEmail ? null : $parsed->value,
            $parsed->isEmail ? $parsed->value : null,
        );

        $password = '';
        for ($i = 0; $i < 16; $i++) {
            $password .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $now = $this->moment->iso();
        $this->users->setPassword($tenant, $ids['user_id'], $this->hasher->hash($password), $now);
        $this->assignments->insert(new RoleAssignment(
            (string) new Ulid(),
            $tenant,
            $ids['person_id'],
            Role::PLATFORM_ADMIN,
            ScopeType::Tenant,
            $tenant,
            null,
            $now,
            null,
            null,
            'console',
            $now,
        ));
        $this->audit->append($tenant, null, 'platform.admin_created', 'user', $ids['user_id'], null, ['person_id' => $ids['person_id'], 'role' => Role::PLATFORM_ADMIN]);

        return $ids + ['login' => $parsed->value, 'password' => $password];
    }
}
