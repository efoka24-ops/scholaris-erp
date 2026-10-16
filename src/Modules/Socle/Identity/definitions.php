<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;
use function DI\get;

use Scholaris\Core\Config\Config;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Identity\Application\AccountProvisioningService;
use Scholaris\Modules\Socle\Identity\Application\ClientAdministratorService;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Application\PasswordHasher;
use Scholaris\Modules\Socle\Identity\Application\PlatformAdministratorService;
use Scholaris\Modules\Socle\Identity\Application\SecretCipher;
use Scholaris\Modules\Socle\Identity\Contracts\AccessibleEstablishments;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Contracts\ClientAdministrators;
use Scholaris\Modules\Socle\Identity\Contracts\PersonAccountLookup;
use Scholaris\Modules\Socle\Identity\Contracts\PlatformAdministrators;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\TileIndicators;
use Scholaris\Modules\Socle\Identity\Contracts\TodoProvider;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Identity\Infrastructure\EmptyTodoProvider;
use Scholaris\Modules\Socle\Identity\Infrastructure\NoTileIndicators;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRecentSecondFactor;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoRoleDirectory;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserPersonLink;
use Scholaris\Modules\Socle\Identity\Infrastructure\RoleBasedAccess;
use Scholaris\Modules\Socle\Identity\Infrastructure\TokenAuthenticator;
use Scholaris\Modules\Socle\Identity\Infrastructure\UserEstablishments;

/**
 * Liaisons du module Identity. Remplacent les refus par défaut du noyau (Authenticator, EstablishmentAccess,
 * PermissionChecker). Réglages facultatifs : `identity.argon_memory`, `identity.argon_time`, `identity.secret_key`
 * (à défaut, variable d'environnement APP_KEY).
 */
return [
    Authenticator::class => get(TokenAuthenticator::class),
    EstablishmentAccess::class => get(RoleBasedAccess::class),
    PermissionChecker::class => get(RoleBasedAccess::class),
    AccountProvisioner::class => get(AccountProvisioningService::class),
    TodoProvider::class => autowire(EmptyTodoProvider::class),
    TileIndicators::class => autowire(NoTileIndicators::class),
    UserPersonLink::class => autowire(PdoUserPersonLink::class),
    PersonAccountLookup::class => get(UserPersonLink::class),
    RoleDirectory::class => autowire(PdoRoleDirectory::class),
    RecentSecondFactor::class => autowire(PdoRecentSecondFactor::class),
    PlatformAdministrators::class => autowire(PlatformAdministratorService::class),
    ClientAdministrators::class => autowire(ClientAdministratorService::class),
    \Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration::class => autowire(\Scholaris\Modules\Socle\Identity\Application\AccountAdministrationService::class),
    AccessibleEstablishments::class => autowire(UserEstablishments::class),
    // S15 : périmètres d'une permission (établissement, unité, structure, classe) ; PermissionChecker::can reste inchangé.
    \Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes::class => autowire(\Scholaris\Modules\Socle\Identity\Infrastructure\RoleBasedPermissionScopes::class),

    Moment::class => factory(static fn (Scholaris\Core\RateLimit\Clock $clock, Config $config): Moment => new Moment($clock, (string) $config->get('app.timezone', 'Africa/Douala'))),

    PasswordHasher::class => factory(static function (Config $config): PasswordHasher {
        $memory = $config->get('identity.argon_memory');
        $time = $config->get('identity.argon_time');

        return new PasswordHasher(is_numeric($memory) ? (int) $memory : null, is_numeric($time) ? (int) $time : null);
    }),

    SecretCipher::class => factory(static function (Config $config): SecretCipher {
        $key = (string) $config->get('identity.secret_key', '');
        if ($key === '') {
            $key = (string) ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? '');
        }

        return new SecretCipher($key);
    }),

    'privacy.tenant_data_source.identity' => \DI\autowire(\Scholaris\Modules\Socle\Identity\Infrastructure\PrivacyExportSource::class),
    'privacy.subject_data_source.identity' => \DI\autowire(\Scholaris\Modules\Socle\Identity\Infrastructure\PrivacySubjectSource::class),
];
