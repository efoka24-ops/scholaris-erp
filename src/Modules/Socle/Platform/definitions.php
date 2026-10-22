<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Core\Http\TenantRestriction;
use Scholaris\Modules\Socle\Platform\Console\AdminCreateCommand;
use Scholaris\Modules\Socle\Platform\Console\BillingRunCommand;
use Scholaris\Modules\Socle\Platform\Console\ClientCreateCommand;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Contracts\RegularisationStatus;
use Scholaris\Modules\Socle\Platform\Infrastructure\PdoApplicationAccess;
use Scholaris\Modules\Socle\Platform\Infrastructure\PdoTenantRestriction;

/**
 * Liaisons du module Platform. Les manifestes d'applications ne sont PAS déclarés ici : chaque application déclare le sien
 * dans son propre definitions.php sous `platform.applications.<clé>` (voir Contracts\ApplicationManifest) ; le catalogue
 * initial est amorcé par migration (table platform_applications).
 */
return [
    ApplicationAccess::class => autowire(PdoApplicationAccess::class),
    RegularisationStatus::class => get(ApplicationAccess::class),
    // Blocage central des routes d'un client en régularisation de paiement (middleware du noyau EnforceTenantRestriction).
    TenantRestriction::class => autowire(PdoTenantRestriction::class),

    'console.command.platform:admin-create' => autowire(AdminCreateCommand::class),
    'console.command.platform:billing-run' => autowire(BillingRunCommand::class),
    'console.command.' . ClientCreateCommand::NAME => autowire(ClientCreateCommand::class),
];
