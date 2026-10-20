<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicPrimary\PublicPrimaryApplication;
use Scholaris\Modules\Apps\PublicPrimary\Registry\Application\InfrastructureSnapshotService;
use Scholaris\Modules\Apps\PublicPrimary\Registry\Policies\RegistryPolicy;

/**
 * Definitions racine de la Primaire publique : manifeste de plateforme (`platform.applications.public_primary`, « coming_soon »),
 * Registry transitoire (remplacé par PublicSchools\Schools, T625), puis fusion des definitions.php des sous-modules
 * (`PublicPrimary/<Module>/definitions.php`, hors Registry qui déclare ses liaisons ci-dessous).
 */
return (static function (): array {
    $definitions = [
        PublicPrimaryApplication::CONTAINER_KEY => PublicPrimaryApplication::manifest(),
        InfrastructureSnapshotService::class => autowire(),
        RegistryPolicy::class => autowire(),
    ];
    foreach (glob(__DIR__ . '/*/definitions.php') ?: [] as $moduleFile) {
        $definitions = array_merge($definitions, (array) require $moduleFile);
    }

    return $definitions;
})();
