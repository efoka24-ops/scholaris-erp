<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\Platform\Http\AccessCodeAdminController;
use Scholaris\Modules\Socle\Platform\Http\AccessRequestAdminController;
use Scholaris\Modules\Socle\Platform\Http\AdministratorsAdminController;
use Scholaris\Modules\Socle\Platform\Http\AuditAdminController;
use Scholaris\Modules\Socle\Platform\Http\ClientApplicationController;
use Scholaris\Modules\Socle\Platform\Http\ClientRegularisationController;
use Scholaris\Modules\Socle\Platform\Http\ClientsAdminController;
use Scholaris\Modules\Socle\Platform\Http\EstablishmentsAdminController;
use Scholaris\Modules\Socle\Platform\Http\PaymentsAdminController;
use Scholaris\Modules\Socle\Platform\Http\PaymentWebhookController;
use Scholaris\Modules\Socle\Platform\Http\PlatformAdminController;
use Scholaris\Modules\Socle\Platform\Http\PricingAdminController;
use Scholaris\Modules\Socle\Platform\Http\PublicAccessController;
use Scholaris\Modules\Socle\Platform\Http\RegularisationAdminController;
use Scholaris\Modules\Socle\Platform\Http\RegularisationRequestsAdminController;

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
    $routes->add('GET', '/platform/settings', [PlatformAdminController::class, 'settings'], $platform);
    $routes->add('PATCH', '/platform/settings', [PlatformAdminController::class, 'updateSettings'], $platform);
    $routes->add('GET', '/platform/settings/contact', [PlatformAdminController::class, 'contact'], $platform);
    $routes->add('PATCH', '/platform/settings/contact', [PlatformAdminController::class, 'updateContact'], $platform);
    $routes->add('GET', '/platform/applications', [PlatformAdminController::class, 'catalog'], $platform);
    $routes->add('PATCH', '/platform/applications/{key}', [PlatformAdminController::class, 'updateCatalog'], $platform);
    $routes->add('GET', '/platform/clients', [ClientsAdminController::class, 'index'], $platform);
    $routes->add('POST', '/platform/clients', [ClientsAdminController::class, 'store'], $platform);
    $routes->add('GET', '/platform/clients/{tenant}', [ClientsAdminController::class, 'show'], $platform);
    $routes->add('PATCH', '/platform/clients/{tenant}', [ClientsAdminController::class, 'update'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/suspend', [ClientsAdminController::class, 'suspend'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/reactivate', [ClientsAdminController::class, 'reactivate'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/archive', [ClientsAdminController::class, 'archive'], $platform);
    $routes->add('GET', '/platform/clients/{tenant}/establishments', [EstablishmentsAdminController::class, 'index'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/establishments', [EstablishmentsAdminController::class, 'store'], $platform);
    $routes->add('PATCH', '/platform/clients/{tenant}/establishments/{establishment}', [EstablishmentsAdminController::class, 'update'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/establishments/{establishment}/close', [EstablishmentsAdminController::class, 'close'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/establishments/{establishment}/reopen', [EstablishmentsAdminController::class, 'reopen'], $platform);
    $routes->add('GET', '/platform/clients/{tenant}/administrators', [AdministratorsAdminController::class, 'index'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/administrators', [AdministratorsAdminController::class, 'store'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/administrators/{user}/resend-invitation', [AdministratorsAdminController::class, 'resend'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/administrators/{user}/block', [AdministratorsAdminController::class, 'block'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/administrators/{user}/unblock', [AdministratorsAdminController::class, 'unblock'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/administrators/{user}/reset-access', [AdministratorsAdminController::class, 'reset'], $platform);
    $routes->add('GET', '/platform/clients/{tenant}/users', [AccessCodeAdminController::class, 'users'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/users/{user}/access-code', [AccessCodeAdminController::class, 'issue'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/users/{user}/access-code/send', [AccessCodeAdminController::class, 'send'], $platform);
    $routes->add('POST', '/platform/administrators/{user}/access-code', [AccessCodeAdminController::class, 'issue'], $platform);
    $routes->add('POST', '/platform/administrators/{user}/access-code/send', [AccessCodeAdminController::class, 'send'], $platform);
    $routes->add('GET', '/platform/administrators', [AdministratorsAdminController::class, 'index'], $platform);
    $routes->add('POST', '/platform/administrators', [AdministratorsAdminController::class, 'store'], $platform);
    $routes->add('POST', '/platform/administrators/{user}/resend-invitation', [AdministratorsAdminController::class, 'resend'], $platform);
    $routes->add('POST', '/platform/administrators/{user}/block', [AdministratorsAdminController::class, 'block'], $platform);
    $routes->add('POST', '/platform/administrators/{user}/unblock', [AdministratorsAdminController::class, 'unblock'], $platform);
    $routes->add('POST', '/platform/administrators/{user}/reset-access', [AdministratorsAdminController::class, 'reset'], $platform);
    $routes->add('GET', '/platform/audit', [AuditAdminController::class, 'index'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/applications', [PlatformAdminController::class, 'openForClient'], $platform);
    $routes->add('PATCH', '/platform/clients/{tenant}/applications/{key}', [PlatformAdminController::class, 'setPrice'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/applications/{key}/suspend', [PlatformAdminController::class, 'suspend'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/applications/{key}/close', [PlatformAdminController::class, 'close'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/applications/{key}/reactivate', [PlatformAdminController::class, 'reactivate'], $platform);
    $routes->add('PATCH', '/platform/clients/{tenant}/applications/{key}/terms', [PricingAdminController::class, 'terms'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/applications/{key}/options', [PricingAdminController::class, 'subscribe'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/applications/{key}/options/{option}/remove', [PricingAdminController::class, 'unsubscribe'], $platform);
    $routes->add('GET', '/platform/service-options', [PricingAdminController::class, 'index'], $platform);
    $routes->add('POST', '/platform/service-options', [PricingAdminController::class, 'store'], $platform);
    $routes->add('PATCH', '/platform/service-options/{key}', [PricingAdminController::class, 'update'], $platform);
    $routes->add('DELETE', '/platform/service-options/{key}', [PricingAdminController::class, 'destroy'], $platform);
    $routes->add('GET', '/platform/regularisation', [RegularisationAdminController::class, 'index'], $platform);
    $routes->add('GET', '/platform/regularisation/export', [RegularisationAdminController::class, 'export'], $platform);
    $routes->add('GET', '/platform/regularisation/declarations', [RegularisationRequestsAdminController::class, 'index'], $platform);
    $routes->add('GET', '/platform/regularisation/declarations/export', [RegularisationRequestsAdminController::class, 'export'], $platform);
    $routes->add('POST', '/platform/regularisation/declarations/{id}/confirm', [RegularisationRequestsAdminController::class, 'confirm'], $platform);
    $routes->add('POST', '/platform/regularisation/declarations/{id}/reject', [RegularisationRequestsAdminController::class, 'reject'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/regularisation/lock', [RegularisationAdminController::class, 'lock'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/regularisation/unlock', [RegularisationAdminController::class, 'unlock'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/regularisation/delay', [RegularisationAdminController::class, 'delay'], $platform);
    $routes->add('POST', '/platform/clients/{tenant}/regularisation/level', [RegularisationAdminController::class, 'level'], $platform);
    $routes->add('GET', '/platform/access-requests', [AccessRequestAdminController::class, 'index'], $platform);
    $routes->add('GET', '/platform/access-requests/{id}', [AccessRequestAdminController::class, 'show'], $platform);
    $routes->add('POST', '/platform/access-requests/{id}/accept', [AccessRequestAdminController::class, 'accept'], $platform);
    $routes->add('POST', '/platform/access-requests/{id}/reject', [AccessRequestAdminController::class, 'reject'], $platform);
    $routes->add('POST', '/platform/access-requests/{id}/request-info', [AccessRequestAdminController::class, 'requestInfo'], $platform);
    $routes->add('POST', '/platform/access-requests/{id}/archive', [AccessRequestAdminController::class, 'archive'], $platform);
    $routes->add('DELETE', '/platform/access-requests/{id}', [AccessRequestAdminController::class, 'destroy'], $platform);
    $routes->add('GET', '/platform/payments', [PlatformAdminController::class, 'payments'], $platform);
    $routes->add('POST', '/platform/payments', [PaymentsAdminController::class, 'store'], $platform);
    $routes->add('POST', '/platform/payments/{id}/cancel', [PaymentsAdminController::class, 'cancel'], $platform);
    $routes->add('GET', '/platform/payments/{id}/receipt', [PaymentsAdminController::class, 'receipt'], $platform);
    $routes->add('POST', '/platform/payments/webhook', [PaymentWebhookController::class, 'receive'], $public);

    $routes->add('GET', '/me/applications', [ClientApplicationController::class, 'mine'], ['tenant' => false]);
    $routes->add('GET', '/me/regularisation', [ClientRegularisationController::class, 'mine'], ['tenant' => false]);
    $routes->add('POST', '/me/regularisation/declarations', [ClientRegularisationController::class, 'declare'], ['tenant' => false]);
    $routes->add('POST', '/applications/{key}/open', [ClientApplicationController::class, 'open']);
    $routes->add('POST', '/applications/{key}/payments', [ClientApplicationController::class, 'pay']);
    $routes->add('GET', '/applications/{key}/payments', [ClientApplicationController::class, 'payments']);
    $routes->add('GET', '/application-payments/{id}/receipt', [ClientApplicationController::class, 'receipt']);
};
