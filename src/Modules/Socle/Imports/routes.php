<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Imports\Http\PeopleImportController;

/**
 * Import en masse des acteurs d'un établissement (T-001). Toutes les routes exigent un établissement actif (X-Establishment-Id) et
 * la permission `imports.people.manage`. Le modèle `template` est déclaré avant `{id}`.
 */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/imports/people/template', [PeopleImportController::class, 'template']);
    $routes->add('POST', '/imports/people', [PeopleImportController::class, 'store']);
    $routes->add('GET', '/imports/people/{id}', [PeopleImportController::class, 'show']);
    $routes->add('GET', '/imports/people/{id}/errors.csv', [PeopleImportController::class, 'errors']);
    $routes->add('POST', '/imports/people/{id}/commit', [PeopleImportController::class, 'commit']);
};
