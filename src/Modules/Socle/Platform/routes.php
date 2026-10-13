<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Platform\Http\AccessRequestAdminController;
use Scholaris\Modules\Socle\Platform\Http\ClientApplicationController;
use Scholaris\Modules\Socle\Platform\Http\PaymentWebhookController;
use Scholaris\Modules\Socle\Platform\Http\PlatformAdminController;
use Scholaris\Modules\Socle\Platform\Http\PublicAccessController;

/**
 * Routes du module Platform. Le préfixe /api/v1 est ajouté par le registre.
 *  - /public/** : sans compte (catalogue, demande d'accès, suivi) ;
 *  - /platform/** : administrateur de plateforme uniquement (client technique `platform`), sans en-tête d'établissement ;
 *  - /me/applications : tout utilisateur d'un client ; /applications/** et /application-payments/** : établissement actif.
 */
return static function (RouteRegistry $routes): void {
    $public = ['public' => true];
    $platform = ['tenant' => false];

    $routes->add('GET', '/public/applications', [PublicAccessController::class, 'applications'], $public);
    $routes->add('POST', '/public/access-requests', [PublicAccessController::class, 'submit'], $public);
    $routes->add('GET', '/public/access-requests/{token}', [PublicAccessController::class, 'track'], $public);
    $routes->add('POST', '/public/access-requests/{token}/complement', [PublicAccessController::class, 'complement'], $public);

    $routes->add('GET', '/platform/dashboard', [PlatformAdminController::class, 'dashboard'], $platform);
    $routes->add('GET', '/platform/applications', [PlatformAdminController::class, 'catalog'], $platform);
    $routes->add('PATCH', '/platform/applications/{key}', [PlatformAdminController::class, 'updateCatalog'], $platform);
    $routes->add('GET', '/platform/clients', [PlatformAdminController::class, 'clients'], $platform);
    $routes->add('GET', '/platform/clients/{tenant}', [PlatformAdminController::class, 'client'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/applications', [PlatformAdminController::class, 'openForClient'], $platform);
    $routes->add('PATCH', '/platform/clients/{tenant}/applications/{key}', [PlatformAdminController::class, 'setPrice'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/applications/{key}/suspend', [PlatformAdminController::class, 'suspend'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/applications/{key}/close', [PlatformAdminController::class, 'close'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/applications/{key}/reactivate', [PlatformAdminController::class, 'reactivate'], $platform);
    $routes->add('GET', '/platform/access-requests', [AccessRequestAdminController::class, 'index'], $platform);
    $routes->add('GET', '/platform/access-requests/{id}', [AccessRequestAdminController::class, 'show'], $platform);
    $routes->add('POST', '/platform/access-requests/{id}/accept', [AccessRequestAdminController::class, 'accept'], $platform);
    $routes->add('POST', '/platform/access-requests/{id}/reject', [AccessRequestAdminController::class, 'reject'], $platform);
    $routes->add('POST', '/platform/access-requests/{id}/request-info', [AccessRequestAdminController::class, 'requestInfo'], $platform);
    $routes->add('GET', '/platform/payments', [PlatformAdminController::class, 'payments'], $platform);
    $routes->add('POST', '/platform/payments/webhook', [PaymentWebhookController::class, 'receive'], $public);

    $routes->add('GET', '/me/applications', [ClientApplicationController::class, 'mine'], ['tenant' => false]);
    $routes->add('POST', '/applications/{key}/open', [ClientApplicationController::class, 'open']);
    $routes->add('POST', '/applications/{key}/payments', [ClientApplicationController::class, 'pay']);
    $routes->add('GET', '/applications/{key}/payments', [ClientApplicationController::class, 'payments']);
    $routes->add('GET', '/application-payments/{id}/receipt', [ClientApplicationController::class, 'receipt']);
};
