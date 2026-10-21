<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicPrimary\PublicPrimaryApplication;

/**
 * Definitions racine de la Primaire publique : manifeste de plateforme (`platform.applications.public_primary`, « coming_soon »),
 * fusion des definitions.php des sous-modules (`PublicPrimary/<Module>/definitions.php`).
 */
return (static function (): array {
    $definitions = [
        PublicPrimaryApplication::CONTAINER_KEY => PublicPrimaryApplication::manifest(),
    ];
    foreach (glob(__DIR__ . '/*/definitions.php') ?: [] as $moduleFile) {
        $definitions = array_merge($definitions, (array) require $moduleFile);
    }

    return $definitions;
})();
