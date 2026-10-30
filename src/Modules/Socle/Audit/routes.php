<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Audit\Http\PrivacyController;

/** Routes de la conformité (S13). Niveau client : pas d'établissement actif exigé. */
return static function (RouteRegistry $routes): void {
    $tenantLevel = ['tenant' => false];

    $routes->add('GET', '/data-requests', [PrivacyController::class, 'index'], $tenantLevel);
    $routes->add('POST', '/data-requests', [PrivacyController::class, 'store'], $tenantLevel);
    $routes->add('GET', '/data-requests/export', [PrivacyController::class, 'exportRequests'], $tenantLevel);
    $routes->add('GET', '/data-requests/{id}', [PrivacyController::class, 'show'], $tenantLevel);
    $routes->add('POST', '/data-requests/{id}/start', [PrivacyController::class, 'start'], $tenantLevel);
    $routes->add('POST', '/data-requests/{id}/refuse', [PrivacyController::class, 'refuse'], $tenantLevel);
    $routes->add('POST', '/data-requests/{id}/approval', [PrivacyController::class, 'approval'], $tenantLevel);
    $routes->add('POST', '/data-requests/{id}/complete', [PrivacyController::class, 'complete'], $tenantLevel);
    $routes->add('GET', '/data-requests/{id}/data', [PrivacyController::class, 'data'], $tenantLevel);

    $routes->add('GET', '/privacy/settings', [PrivacyController::class, 'showSettings'], $tenantLevel);
    $routes->add('PUT', '/privacy/settings', [PrivacyController::class, 'updateSettings'], $tenantLevel);
    $routes->add('GET', '/privacy/processing-register', [PrivacyController::class, 'processingRegister'], $tenantLevel);
    $routes->add('GET', '/audit-log/export', [PrivacyController::class, 'exportAuditLog'], $tenantLevel);
    $routes->add('POST', '/privacy/tenant-export', [PrivacyController::class, 'tenantExport'], $tenantLevel);
};
