<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Les portails passent par /portal/* du socle : la section « primary » n'ajoute aucune route propre. */
return static function (PrimaryRoutes $routes): void {
};
