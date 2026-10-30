<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\HigherApplication;

/**
 * Unique definitions.php de l'application Supérieur chargé par le noyau : manifeste de plateforme (`platform.applications.higher`),
 * puis fusion (array_merge) des definitions.php de chaque sous-module (`Higher/<Module>/definitions.php`, tableau PHP-DI).
 * Clés des sous-modules : leurs propres classes, ou des clés préfixées `higher.<module>.` pour les listes enregistrables.
 * Le vocabulaire `higher` est déjà enregistré par Organisation (DefaultApplications), voir HigherApplication::descriptor().
 * Fermeture immédiate : aucune variable ne fuit dans la portée du chargeur (ContainerFactory).
 */
return (static function (): array {
    $definitions = [
        HigherApplication::CONTAINER_KEY => HigherApplication::manifest(),
    ];
    foreach (glob(__DIR__ . '/*/definitions.php') ?: [] as $moduleFile) {
        $definitions = array_merge($definitions, (array) require $moduleFile);
    }

    return $definitions;
})();
