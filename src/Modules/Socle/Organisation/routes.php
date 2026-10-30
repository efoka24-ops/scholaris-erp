<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\AcademicYearController;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\AuthorizationController;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\EstablishmentController;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\OrgUnitController;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\PeriodController;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\RoomController;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\SettingsController;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\StructureController;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\TerritoryController;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\VocabularyController;
use Scholaris\Modules\Socle\Organisation\Http\Controllers\YearClosureController;

/**
 * Routes du service S02 Organisation. Les routes d'établissements (liste, création, identité, modules) et le
 * vocabulaire n'exigent pas d'établissement actif ; toutes les autres exigent X-Establishment-Id.
 */
return static function (RouteRegistry $routes): void {
    $noEstablishment = ['tenant' => false];

    $routes->add('GET', '/establishments', [EstablishmentController::class, 'index'], $noEstablishment);
    $routes->add('POST', '/establishments', [EstablishmentController::class, 'store'], $noEstablishment);
    $routes->add('GET', '/establishments/export', [EstablishmentController::class, 'export'], $noEstablishment);
    $routes->add('GET', '/establishments/{id}', [EstablishmentController::class, 'show'], $noEstablishment);
    $routes->add('PATCH', '/establishments/{id}', [EstablishmentController::class, 'update'], $noEstablishment);
    $routes->add('GET', '/establishments/{id}/branding', [EstablishmentController::class, 'branding'], $noEstablishment);
    $routes->add('PUT', '/establishments/{id}/branding', [EstablishmentController::class, 'updateBranding'], $noEstablishment);
    $routes->add('GET', '/establishments/{id}/modules', [EstablishmentController::class, 'modules'], $noEstablishment);
    $routes->add('PUT', '/establishments/{id}/modules', [EstablishmentController::class, 'updateModules'], $noEstablishment);

    $routes->add('GET', '/vocabulary', [VocabularyController::class, 'show'], $noEstablishment);

    // Hiérarchie territoriale versionnée : les droits restent bornés au tenant/établissement résolu.
    $routes->add('GET', '/territories', [TerritoryController::class, 'index']);
    $routes->add('POST', '/territories', [TerritoryController::class, 'store']);
    $routes->add('POST', '/territories/{id}/versions', [TerritoryController::class, 'version']);
    // PP1 : rattachement daté d'établissements, droit de niveau client (aucun établissement actif requis).
    $routes->add('PUT', '/territories/{id}/establishments', [TerritoryController::class, 'assignEstablishments'], $noEstablishment);
    $routes->add('GET', '/territories/{id}/lineage', [TerritoryController::class, 'lineage']);
    $routes->add('GET', '/territories/{id}/descendants', [TerritoryController::class, 'descendants']);
    $routes->add('GET', '/territories/{id}', [TerritoryController::class, 'show']);

    // Routes statiques avant les routes à paramètre (exigence de FastRoute).
    $routes->add('GET', '/authorizations/expiring', [AuthorizationController::class, 'expiring']);

    foreach ([
        '/org-units' => OrgUnitController::class,
        '/academic-years' => AcademicYearController::class,
        '/periods' => PeriodController::class,
        '/structures' => StructureController::class,
        '/authorizations' => AuthorizationController::class,
        '/rooms' => RoomController::class,
    ] as $path => $controller) {
        $routes->add('GET', $path, [$controller, 'index']);
        $routes->add('POST', $path, [$controller, 'store']);
        $routes->add('GET', $path . '/export', [$controller, 'export']);
        $routes->add('GET', $path . '/{id}', [$controller, 'show']);
        $routes->add('PATCH', $path . '/{id}', [$controller, 'update']);
    }
    // Clôture d'année (T137) : contrôle préalable, demande, approbation avec 2FA récente, refus.
    $routes->add('GET', '/academic-years/{id}/closure-check', [YearClosureController::class, 'check']);
    $routes->add('POST', '/academic-years/{id}/close', [YearClosureController::class, 'close']);
    $routes->add('POST', '/academic-years/{id}/close/approve', [YearClosureController::class, 'approve']);
    $routes->add('POST', '/academic-years/{id}/close/reject', [YearClosureController::class, 'reject']);
    $routes->add('GET', '/structures/{id}/enrolment', [StructureController::class, 'enrolment']);

    $routes->add('GET', '/settings', [SettingsController::class, 'index']);
    $routes->add('PUT', '/settings', [SettingsController::class, 'put']);
    $routes->add('GET', '/settings/effective', [SettingsController::class, 'effective']);
};
