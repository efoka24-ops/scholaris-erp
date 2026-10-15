<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Documents\Http\Controllers\BatchController;
use Scholaris\Modules\Socle\Documents\Http\Controllers\DocumentController;
use Scholaris\Modules\Socle\Documents\Http\Controllers\RequestController;
use Scholaris\Modules\Socle\Documents\Http\Controllers\SealController;
use Scholaris\Modules\Socle\Documents\Http\Controllers\TemplateController;
use Scholaris\Modules\Socle\Documents\Http\Controllers\TypeController;
use Scholaris\Modules\Socle\Documents\Http\Controllers\VerifyController;

/** Routes du service S06 Documents. Toutes exigent un établissement actif, sauf la vérification publique. */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/public/verify/{token}', [VerifyController::class, 'show'], ['public' => true]);

    $routes->add('GET', '/document-types', [TypeController::class, 'index']);
    $routes->add('POST', '/document-types', [TypeController::class, 'store']);
    $routes->add('GET', '/document-types/{id}', [TypeController::class, 'show']);
    $routes->add('PATCH', '/document-types/{id}', [TypeController::class, 'update']);

    $routes->add('GET', '/document-templates', [TemplateController::class, 'index']);
    $routes->add('POST', '/document-templates', [TemplateController::class, 'store']);
    $routes->add('GET', '/document-templates/{id}', [TemplateController::class, 'show']);
    $routes->add('PATCH', '/document-templates/{id}', [TemplateController::class, 'update']);
    $routes->add('DELETE', '/document-templates/{id}', [TemplateController::class, 'destroy']);
    $routes->add('POST', '/document-templates/{id}/validate', [TemplateController::class, 'validateTemplate']);
    $routes->add('POST', '/document-templates/{id}/versions', [TemplateController::class, 'newVersion']);

    $routes->add('GET', '/document-seal', [SealController::class, 'show']);
    $routes->add('PUT', '/document-seal', [SealController::class, 'put']);

    // Route statique avant les routes à paramètre (exigence de FastRoute).
    $routes->add('GET', '/documents/export', [DocumentController::class, 'export']);
    $routes->add('GET', '/documents', [DocumentController::class, 'index']);
    $routes->add('POST', '/documents', [DocumentController::class, 'store']);
    $routes->add('GET', '/documents/{id}', [DocumentController::class, 'show']);
    $routes->add('GET', '/documents/{id}/preview', [DocumentController::class, 'preview']);
    $routes->add('POST', '/documents/{id}/duplicate', [DocumentController::class, 'duplicate']);
    $routes->add('POST', '/documents/{id}/revoke', [DocumentController::class, 'revoke']);

    $routes->add('GET', '/document-batches', [BatchController::class, 'index']);
    $routes->add('GET', '/document-batches/export', [BatchController::class, 'export']);
    $routes->add('POST', '/document-batches', [BatchController::class, 'store']);
    $routes->add('GET', '/document-batches/{id}', [BatchController::class, 'show']);
    $routes->add('GET', '/document-batches/{id}/excluded', [BatchController::class, 'excluded']);
    $routes->add('POST', '/document-batches/{id}/process', [BatchController::class, 'process']);

    $routes->add('GET', '/document-requests', [RequestController::class, 'index']);
    $routes->add('GET', '/document-requests/export', [RequestController::class, 'export']);
    $routes->add('POST', '/document-requests', [RequestController::class, 'store']);
    $routes->add('POST', '/document-requests/{id}/fulfill', [RequestController::class, 'fulfill']);
    $routes->add('POST', '/document-requests/{id}/reject', [RequestController::class, 'reject']);
};
