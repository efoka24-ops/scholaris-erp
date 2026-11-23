<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Setup\Http\ProfileController;
use Scholaris\Modules\Apps\Secondary\Setup\Http\SetupController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/**
 * Paramétrage (noyau, préfixe /secondary) : profil (lecture, ouverture T815, changements avec journal T838), niveaux, séries et
 * spécialités ouvertes, classes, matières, coefficients et volumes versionnés, salles, règlement intérieur (T820 à T822).
 */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/setup/profile', [ProfileController::class, 'show']);
    $routes->post('/setup/profile/open', [ProfileController::class, 'open']);
    $routes->get('/setup/profile/changes', [ProfileController::class, 'changes']);
    $routes->post('/setup/profile/changes', [ProfileController::class, 'change']);

    $routes->get('/setup/levels', [SetupController::class, 'levels']);
    $routes->post('/setup/levels', [SetupController::class, 'createLevel']);
    $routes->post('/setup/levels/examples', [SetupController::class, 'loadExampleLevels']);
    $routes->patch('/setup/levels/{id}', [SetupController::class, 'updateLevel']);

    $routes->get('/setup/streams', [SetupController::class, 'streams']);
    $routes->post('/setup/streams', [SetupController::class, 'openStream']);
    $routes->patch('/setup/streams/{id}', [SetupController::class, 'updateStream']);

    $routes->get('/setup/classes', [SetupController::class, 'classes']);
    $routes->post('/setup/classes', [SetupController::class, 'createClass']);
    $routes->patch('/setup/classes/{id}', [SetupController::class, 'updateClass']);

    $routes->get('/setup/subjects', [SetupController::class, 'subjects']);
    $routes->post('/setup/subjects', [SetupController::class, 'createSubject']);
    $routes->patch('/setup/subjects/{id}', [SetupController::class, 'updateSubject']);

    $routes->get('/setup/coefficients', [SetupController::class, 'coefficients']);
    $routes->post('/setup/coefficients', [SetupController::class, 'setCoefficient']);
    $routes->get('/setup/coefficients/freezes', [SetupController::class, 'freezes']);
    $routes->post('/setup/coefficients/freeze', [SetupController::class, 'freezeCoefficients']);
    $routes->post('/setup/coefficients/copy', [SetupController::class, 'copyCoefficients']);

    $routes->get('/setup/rooms', [SetupController::class, 'rooms']);
    $routes->post('/setup/rooms', [SetupController::class, 'saveRoom']);

    $routes->get('/setup/rules', [SetupController::class, 'rules']);
    $routes->put('/setup/rules/{key}', [SetupController::class, 'setRule']);
};
