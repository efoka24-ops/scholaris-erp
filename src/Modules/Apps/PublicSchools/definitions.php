<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Policies\ScopeResolver;
use Scholaris\Modules\Apps\PublicSchools\PublicSchoolsApplication;
use Scholaris\Modules\Apps\PublicSchools\Support\GatedAction;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Apps\PublicSchools\Support\WriteGate;

/**
 * Unique definitions.php de l'application chargé par le noyau : manifeste de plateforme (`platform.applications.public_schools`,
 * statut « coming_soon »), mécanismes communs, puis fusion (array_merge) des definitions.php de chaque sous-module
 * (`PublicSchools/<Module>/definitions.php`, tableau PHP-DI). Clés des sous-modules : leurs propres classes, ou des clés préfixées
 * `public_schools.<module>.` pour les listes enregistrables. Fermeture immédiate : aucune variable ne fuit dans la portée du chargeur.
 */
return (static function (): array {
    $definitions = [
        PublicSchoolsApplication::CONTAINER_KEY => PublicSchoolsApplication::manifest(),
        WriteGate::class => autowire(),
        GatedAction::class => autowire(),
        ScopeResolver::class => autowire(),
        ScopedByTerritory::class => autowire(),
    ];
    foreach (glob(__DIR__ . '/*/definitions.php') ?: [] as $moduleFile) {
        $definitions = array_merge($definitions, (array) require $moduleFile);
    }

    return $definitions;
})();
