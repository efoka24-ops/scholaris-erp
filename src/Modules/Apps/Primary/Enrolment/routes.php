<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Enrolment\Http\LearnerController;
use Scholaris\Modules\Apps\Primary\Support\PrimaryRoutes;

/** Dossier élève du primaire : profil (redoublant calculé) et contrôle de la table de passage. */
return static function (PrimaryRoutes $routes): void {
    $routes->get('/learners/{id}/profile', [LearnerController::class, 'profile']);
    $routes->get('/promotion-map/check', [LearnerController::class, 'promotionCheck']);
};
