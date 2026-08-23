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

use Scholaris\Application;
use Scholaris\Controller\AuthController;
use Scholaris\Controller\ClassroomController;
use Scholaris\Controller\DashboardController;
use Scholaris\Controller\EnrollmentController;
use Scholaris\Controller\EstablishmentRequestController;
use Scholaris\Controller\FinanceController;
use Scholaris\Controller\GradeController;
use Scholaris\Controller\MobileMoneyController;
use Scholaris\Controller\PublicController;
use Scholaris\Controller\StudentController;
use Scholaris\Http\Request;
use Scholaris\Http\Response;

$router = $app->router();

// --- Acces public ---------------------------------------------------------

// Page d'accueil publique : presentation, pre-inscription, demande d'ouverture.
$router->guest('GET', '/', [PublicController::class, 'home']);

$router->guest('GET', '/login', [AuthController::class, 'showLogin']);
$router->guest('POST', '/login', [AuthController::class, 'login']);

// Pre-inscription en ligne : un parent depose un dossier sans avoir de compte.
$router->guest('GET', '/pre-inscription', [PublicController::class, 'preEnrollmentForm']);
$router->guest('POST', '/pre-inscription', [PublicController::class, 'submitPreEnrollment']);

// Demande de creation d'etablissement, instruite ensuite par le Super Admin.
$router->guest('GET', '/demande-etablissement', [PublicController::class, 'establishmentRequestForm']);
$router->guest('POST', '/demande-etablissement', [PublicController::class, 'submitEstablishmentRequest']);

// Callback pawaPay : appele par la passerelle, donc sans session. La signature
// RFC-9421 y remplace l'authentification, verifiee dans le controleur, qui
// refuse tout callback non signe ou mal signe.
$router->guest('POST', '/webhooks/pawapay', [MobileMoneyController::class, 'callback']);

// Sonde de disponibilite : verifie que PHP repond et que la base est joignable.
$router->guest('GET', '/up', static function (Request $request, Application $app): Response {
    try {
        $app->db()->scalar('SELECT 1');

        return Response::json(['status' => 'ok']);
    } catch (Throwable $e) {
        return Response::json(['status' => 'degraded'], 503);
    }
});

// --- Espace authentifie ---------------------------------------------------

$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [DashboardController::class, 'index']);

// Module 4 : eleves
$router->get('/students', [StudentController::class, 'index'], 'students:read');
$router->get('/students/create', [StudentController::class, 'create'], 'students:create');
$router->post('/students', [StudentController::class, 'store'], 'students:create');
$router->get('/students/{id}', [StudentController::class, 'show'], 'students:read');
$router->get('/students/{id}/edit', [StudentController::class, 'edit'], 'students:update');
$router->post('/students/{id}', [StudentController::class, 'update'], 'students:update');
$router->post('/students/{id}/delete', [StudentController::class, 'destroy'], 'students:update');

// Module 2 : classes
$router->get('/classrooms', [ClassroomController::class, 'index'], 'classrooms:read');
$router->get('/classrooms/create', [ClassroomController::class, 'create'], 'classrooms:create');
$router->post('/classrooms', [ClassroomController::class, 'store'], 'classrooms:create');
$router->get('/classrooms/{id}', [ClassroomController::class, 'show'], 'classrooms:read');
$router->get('/classrooms/{id}/edit', [ClassroomController::class, 'edit'], 'classrooms:update');
$router->post('/classrooms/{id}', [ClassroomController::class, 'update'], 'classrooms:update');

// Module 4 : inscriptions
$router->get('/enrollments', [EnrollmentController::class, 'index'], 'enrollments:read');
$router->get('/enrollments/create', [EnrollmentController::class, 'create'], 'enrollments:create');
$router->post('/enrollments', [EnrollmentController::class, 'store'], 'enrollments:create');
$router->post('/enrollments/{id}/cancel', [EnrollmentController::class, 'cancel'], 'enrollments:update');

// Module 5 : notes et moyennes
$router->get('/grades', [GradeController::class, 'index'], 'grades:read');
$router->get('/grades/entry/{classroom}/{subject}/{period}', [GradeController::class, 'entry'], 'grades:read');
$router->post('/grades/entry/{classroom}/{subject}/{period}', [GradeController::class, 'store'], 'grades:create');
$router->post('/grades/calculate/{classroom}/{period}', [GradeController::class, 'calculate'], 'grades:calculate');
$router->get('/grades/results/{classroom}/{period}', [GradeController::class, 'results'], 'grades:read');
$router->post('/grades/publish/{classroom}/{period}', [GradeController::class, 'publish'], 'grades:publish');

// Module 7 : finance
$router->get('/finance', [FinanceController::class, 'dashboard'], 'finance-dashboard:read');
$router->get('/finance/invoices', [FinanceController::class, 'invoices'], 'invoices:read');
$router->get('/finance/invoices/{id}', [FinanceController::class, 'invoice'], 'invoices:read');
$router->post('/finance/invoices/{id}/payments', [FinanceController::class, 'storePayment'], 'payments:create');
$router->get('/finance/receipts/{id}', [FinanceController::class, 'receipt'], 'payments:read');
// Encaissement Mobile Money (pawaPay)
$router->get('/finance/invoices/{id}/mobile-money', [MobileMoneyController::class, 'form'], 'payments:create');
$router->post('/finance/invoices/{id}/mobile-money', [MobileMoneyController::class, 'initiate'], 'payments:create');
$router->get('/finance/transactions/{id}', [MobileMoneyController::class, 'show'], 'payments:read');
$router->post('/finance/transactions/{id}/refresh', [MobileMoneyController::class, 'refresh'], 'payments:read');

$router->get('/finance/fee-structures', [FinanceController::class, 'feeStructures'], 'fee-structures:read');
$router->post('/finance/fee-structures', [FinanceController::class, 'storeFeeStructure'], 'fee-structures:create');

// Administration de la plateforme : demandes de creation d'etablissement.
// Le controleur verifie lui-meme le role Super Admin, ces demandes n'etant
// rattachees a aucun etablissement.
$router->get('/admin/etablissements', [EstablishmentRequestController::class, 'index'], 'tenants:read');
$router->post('/admin/etablissements/{id}/approuver', [EstablishmentRequestController::class, 'approve'], 'tenants:create');
$router->post('/admin/etablissements/{id}/refuser', [EstablishmentRequestController::class, 'reject'], 'tenants:update');
