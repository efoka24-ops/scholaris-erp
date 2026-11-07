<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Higher\Documents\Http\DocumentsController;
use Scholaris\Modules\Apps\Higher\Support\HigherRoutes;

/**
 * Routes du module DOC de l'IPES (préfixe /higher, WriteGate avant chaque écriture). La page publique de vérification (QR) est
 * celle du socle : GET /api/v1/public/verify/{token} (RG-DOC-2, sans les notes) ; aucune route Higher n'est publique.
 */
return static function (HigherRoutes $routes): void {
    // Routes statiques avant les routes à paramètre (FastRoute).
    $routes->get('/documents/catalog', [DocumentsController::class, 'catalog']);
    $routes->get('/documents/catalog/{code}', [DocumentsController::class, 'type']);
    $routes->get('/documents/schools/{schoolId}/profile', [DocumentsController::class, 'profile']);
    $routes->put('/documents/schools/{schoolId}/profile', [DocumentsController::class, 'putProfile']);
    $routes->post('/documents/issue', [DocumentsController::class, 'issue']);

    $routes->get('/documents', [DocumentsController::class, 'index']);
    $routes->get('/documents/{id}', [DocumentsController::class, 'show']);
    $routes->post('/documents/{id}/cancel', [DocumentsController::class, 'cancel']);
    $routes->post('/documents/{id}/duplicate', [DocumentsController::class, 'duplicate']);
};
