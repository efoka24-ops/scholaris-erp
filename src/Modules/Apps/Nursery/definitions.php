<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Nursery\NurseryApplication;

/** Définitions racine : le manifeste de plateforme puis celles de chaque sous-module (`<Module>/definitions.php`). */
return (static function (): array {
    $definitions = [NurseryApplication::CONTAINER_KEY => NurseryApplication::manifest()];
    foreach (glob(__DIR__ . '/*/definitions.php') ?: [] as $file) {
        $definitions = array_merge($definitions, (array) require $file);
    }

    return $definitions;
})();
