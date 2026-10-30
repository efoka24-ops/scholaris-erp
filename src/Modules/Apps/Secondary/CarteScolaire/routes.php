<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Http\CarteScolaireController;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryRoutes;

/** Échanges par fichiers avec cartescolaire.cm (noyau, T828, T829) : matricules, écarts, paiements constatés (API-03). */
return static function (SecondaryRoutes $routes): void {
    $routes->get('/carte-scolaire/settings', [CarteScolaireController::class, 'settings']);
    $routes->get('/carte-scolaire/students', [CarteScolaireController::class, 'students']);
    $routes->put('/carte-scolaire/students/{id}/national-id', [CarteScolaireController::class, 'recordNationalId']);
    $routes->post('/carte-scolaire/national-ids/import', [CarteScolaireController::class, 'importNationalIds']);
    $routes->post('/carte-scolaire/payments/import', [CarteScolaireController::class, 'importPayments']);
    $routes->get('/carte-scolaire/payments', [CarteScolaireController::class, 'payments']);
    $routes->get('/carte-scolaire/imports', [CarteScolaireController::class, 'imports']);
    $routes->get('/carte-scolaire/imports/{id}', [CarteScolaireController::class, 'import']);
    $routes->get('/carte-scolaire/discrepancies', [CarteScolaireController::class, 'discrepancies']);
    $routes->post('/carte-scolaire/discrepancies/{id}/resolve', [CarteScolaireController::class, 'resolve']);
};
