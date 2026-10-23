<?php

declare(strict_types=1);

use Scholaris\Core\Http\RouteRegistry;
use Scholaris\Modules\Socle\People\Http\ConsentController;
use Scholaris\Modules\Socle\People\Http\DocumentController;
use Scholaris\Modules\Socle\People\Http\EnrollmentController;
use Scholaris\Modules\Socle\People\Http\ImportController;
use Scholaris\Modules\Socle\People\Http\PeopleController;
use Scholaris\Modules\Socle\People\Http\PersonDetailController;
use Scholaris\Modules\Socle\People\Http\RelationController;

/**
 * Routes du service S03 Personnes. Toutes exigent un établissement actif (X-Establishment-Id), sauf le téléchargement
 * d'une pièce, protégé par une signature HMAC temporaire.
 */
return static function (RouteRegistry $routes): void {
    $routes->add('GET', '/people', [PeopleController::class, 'index']);
    $routes->add('POST', '/people', [PeopleController::class, 'store']);
    $routes->add('GET', '/people/export', [PeopleController::class, 'export']);
    $routes->add('GET', '/people/duplicates', [PeopleController::class, 'duplicates']);
    $routes->add('POST', '/people/import', [ImportController::class, 'store']);
    $routes->add('GET', '/people/{id}', [PeopleController::class, 'show']);
    $routes->add('PATCH', '/people/{id}', [PeopleController::class, 'update']);
    $routes->add('DELETE', '/people/{id}', [PeopleController::class, 'destroy']);

    $routes->add('GET', '/people/{id}/contacts', [PersonDetailController::class, 'contacts']);
    $routes->add('POST', '/people/{id}/contacts', [PersonDetailController::class, 'addContact']);
    $routes->add('DELETE', '/people/{id}/contacts/{contactId}', [PersonDetailController::class, 'deleteContact']);
    $routes->add('GET', '/people/{id}/identifiers', [PersonDetailController::class, 'identifiers']);
    $routes->add('POST', '/people/{id}/identifiers', [PersonDetailController::class, 'addIdentifier']);
    $routes->add('DELETE', '/people/{id}/identifiers/{identifierId}', [PersonDetailController::class, 'deleteIdentifier']);
    $routes->add('GET', '/people/{id}/schooling-history', [PersonDetailController::class, 'history']);
    $routes->add('POST', '/people/{id}/schooling-history', [PersonDetailController::class, 'addHistory']);
    $routes->add('GET', '/people/{id}/enrollments', [PersonDetailController::class, 'enrollments']);
    $routes->add('GET', '/people/{id}/health', [PersonDetailController::class, 'health']);
    $routes->add('PUT', '/people/{id}/health', [PersonDetailController::class, 'updateHealth']);

    $routes->add('GET', '/people/{id}/relations', [RelationController::class, 'index']);
    $routes->add('POST', '/people/{id}/relations', [RelationController::class, 'store']);
    $routes->add('DELETE', '/people/{id}/relations/{relationId}', [RelationController::class, 'destroy']);

    $routes->add('GET', '/people/{id}/documents', [DocumentController::class, 'index']);
    $routes->add('POST', '/people/{id}/documents', [DocumentController::class, 'store']);
    $routes->add('DELETE', '/people/{id}/documents/{docId}', [DocumentController::class, 'destroy']);
    $routes->add('GET', '/people/{id}/documents/{docId}/link', [DocumentController::class, 'link']);
    $routes->add('GET', '/people/{id}/documents/{docId}/download', [DocumentController::class, 'download'], ['public' => true]);

    $routes->add('GET', '/consents', [ConsentController::class, 'index']);
    $routes->add('POST', '/consents', [ConsentController::class, 'store']);
    $routes->add('POST', '/consents/{id}/revoke', [ConsentController::class, 'revoke']);

    $routes->add('GET', '/enrollments', [EnrollmentController::class, 'index']);
    $routes->add('GET', '/enrollments/export', [EnrollmentController::class, 'export']);
    $routes->add('POST', '/enrollments', [EnrollmentController::class, 'store']);
    $routes->add('GET', '/enrollments/{id}', [EnrollmentController::class, 'show']);
    $routes->add('PATCH', '/enrollments/{id}', [EnrollmentController::class, 'update']);
    $routes->add('DELETE', '/enrollments/{id}', [EnrollmentController::class, 'destroy']);
    $routes->add('POST', '/enrollments/{id}/validate', [EnrollmentController::class, 'validate']);
    $routes->add('POST', '/enrollments/{id}/status', [EnrollmentController::class, 'status']);
};
