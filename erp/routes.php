<?php

declare(strict_types=1);

/**
 * Table des routes.
 *
 * Le troisieme argument est la permission RBAC exigee. Declarer l'habilitation
 * ici, a cote du chemin, rend l'ensemble des acces lisible d'un seul coup
 * d'oeil : une route sans permission se voit immediatement.
 *
 * @var \Scholaris\Application $app
 */

use Scholaris\Controller\AuthController;
use Scholaris\Controller\DashboardController;
use Scholaris\Controller\StudentController;
use Scholaris\Http\Request;
use Scholaris\Http\Response;

$router = $app->router();

// --- Acces public ---------------------------------------------------------

$router->guest('GET', '/login', [AuthController::class, 'showLogin']);
$router->guest('POST', '/login', [AuthController::class, 'login']);

// Sonde de disponibilite, utile pour verifier que PHP et la base repondent.
$router->guest('GET', '/up', static function (Request $request, Scholaris\Application $app): Response {
    try {
        $app->db()->scalar('SELECT 1');

        return Response::json(['status' => 'ok']);
    } catch (Throwable $e) {
        return Response::json(['status' => 'degraded'], 503);
    }
});

// --- Espace authentifie ---------------------------------------------------

$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/', static fn (): Response => Response::redirect('/dashboard'));
$router->get('/dashboard', [DashboardController::class, 'index']);

// Module 4 : eleves
$router->get('/students', [StudentController::class, 'index'], 'students:read');
$router->get('/students/create', [StudentController::class, 'create'], 'students:create');
$router->post('/students', [StudentController::class, 'store'], 'students:create');
$router->get('/students/{id}', [StudentController::class, 'show'], 'students:read');
$router->get('/students/{id}/edit', [StudentController::class, 'edit'], 'students:update');
$router->post('/students/{id}', [StudentController::class, 'update'], 'students:update');
$router->post('/students/{id}/delete', [StudentController::class, 'destroy'], 'students:update');
