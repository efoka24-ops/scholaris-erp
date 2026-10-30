<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Documents\Http\DocumentsController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/** Documents du secondaire (noyau). Les documents d'un module (stages, ateliers, paie, examens) déclarent leur module dans leur type. */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/documents/kinds', [DocumentsController::class, 'kinds']);
    $routes->get('/documents/issued', [DocumentsController::class, 'issued']);
    $routes->post('/documents', [DocumentsController::class, 'issue']);
};
