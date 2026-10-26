<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicPrimary\Promotion\Http\PromotionController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    // PAS : politique nationale (RG-PAS-1), propositions, conseil des maîtres (API-08), avis de l'inspecteur (RG-PAS-2), recours, PV DOC-12.
    $routes->get('/promotion/policy', [PromotionController::class, 'policy']);
    $routes->put('/promotion/policy', [PromotionController::class, 'setPolicy']);
    $routes->post('/promotion/decisions/{id}/opinion', [PromotionController::class, 'opinion']);
    $routes->post('/promotion/decisions/{id}/appeal', [PromotionController::class, 'appeal']);
    $routes->post('/promotion/councils/{id}/minutes', [PromotionController::class, 'minutes']);
    $routes->get('/promotion/{year}', [PromotionController::class, 'index']);
    $routes->post('/promotion/{year}/proposals', [PromotionController::class, 'propose']);
    $routes->post('/promotion/{year}/council', [PromotionController::class, 'council']);
    $routes->get('/promotion/{year}/statistics', [PromotionController::class, 'statistics']);
};
