<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Academic\Http\ReferentialController;
use Scholaris\Modules\Apps\Primary\Academic\Http\TemplateController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Référentiel du primaire (préfixe /primary ; WriteGate avant chaque écriture). */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/level-profiles', [ReferentialController::class, 'profiles']);
    $routes->post('/level-profiles', [ReferentialController::class, 'createProfile']);
    $routes->get('/level-profiles/{id}', [ReferentialController::class, 'showProfile']);
    $routes->patch('/level-profiles/{id}', [ReferentialController::class, 'updateProfile']);

    $routes->get('/domains', [ReferentialController::class, 'domains']);
    $routes->post('/domains', [ReferentialController::class, 'createDomain']);
    $routes->patch('/domains/{id}', [ReferentialController::class, 'updateDomain']);

    $routes->get('/level-subjects', [ReferentialController::class, 'levelSubjects']);
    $routes->post('/level-subjects', [ReferentialController::class, 'createLevelSubject']);
    $routes->patch('/level-subjects/{id}', [ReferentialController::class, 'updateLevelSubject']);

    $routes->get('/competencies', [ReferentialController::class, 'competencies']);
    $routes->post('/competencies', [ReferentialController::class, 'createCompetency']);
    $routes->patch('/competencies/{id}', [ReferentialController::class, 'updateCompetency']);

    $routes->get('/referential/export', [ReferentialController::class, 'export']);
    $routes->get('/referentials', [TemplateController::class, 'index']);
    $routes->post('/referentials/copy-year', [TemplateController::class, 'copyYear']);
    $routes->get('/referentials/{code}', [TemplateController::class, 'show']);
    $routes->post('/referentials/{code}/apply', [TemplateController::class, 'apply']);
};
