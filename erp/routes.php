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
use Scholaris\Controller\AccountingController;
use Scholaris\Controller\AppointmentController;
use Scholaris\Controller\AuthController;
use Scholaris\Controller\DocumentController;
use Scholaris\Controller\ObjectiveController;
use Scholaris\Controller\PayrollController;
use Scholaris\Controller\ProcurementController;
use Scholaris\Controller\PublicAdministrationController;
use Scholaris\Controller\StockController;
use Scholaris\Controller\ClassroomController;
use Scholaris\Controller\DashboardController;
use Scholaris\Controller\EnrollmentController;
use Scholaris\Controller\EstablishmentRequestController;
use Scholaris\Controller\FinanceController;
use Scholaris\Controller\GradeController;
use Scholaris\Controller\MobileMoneyController;
use Scholaris\Controller\BulletinController;
use Scholaris\Controller\CommunicationController;
use Scholaris\Controller\ExamController;
use Scholaris\Controller\HealthController;
use Scholaris\Controller\HrController;
use Scholaris\Controller\LibraryController;
use Scholaris\Controller\LogisticsController;
use Scholaris\Controller\PlatformController;
use Scholaris\Controller\PlatformReportController;
use Scholaris\Controller\PlatformUserController;
use Scholaris\Controller\SchoolLifeController;
use Scholaris\Controller\CourseLogController;
use Scholaris\Controller\PublicController;
use Scholaris\Controller\AcademicYearController;
use Scholaris\Controller\MaintenanceController;
use Scholaris\Controller\SettingsController;
use Scholaris\Controller\TenantAdminController;
use Scholaris\Controller\StudentController;
use Scholaris\Http\Request;
use Scholaris\Http\Response;

$router = $app->router();

// --- Acces public ---------------------------------------------------------

// Page d'accueil publique : presentation, pre-inscription, demande d'ouverture.
$router->guest('GET', '/', [PublicController::class, 'home']);

$router->guest('GET', '/login', [AuthController::class, 'showLogin']);
$router->guest('POST', '/login', [AuthController::class, 'login']);

// Activation d'un compte cree par un tiers (etablissement, plateforme) : le
// titulaire choisit lui-meme son mot de passe via un lien a duree limitee.
$router->guest('GET', '/activation/{token}', [AuthController::class, 'showActivate']);
$router->guest('POST', '/activation/{token}', [AuthController::class, 'activate']);

// Pre-inscription en ligne : un parent depose un dossier sans avoir de compte.
$router->guest('GET', '/pre-inscription', [PublicController::class, 'preEnrollmentForm']);
$router->guest('POST', '/pre-inscription', [PublicController::class, 'submitPreEnrollment']);

// Demande de creation d'etablissement, instruite ensuite par le Super Admin.
$router->guest('GET', '/demande-etablissement', [PublicController::class, 'establishmentRequestForm']);
$router->guest('POST', '/demande-etablissement', [PublicController::class, 'submitEstablishmentRequest']);

// Suivi d'un dossier par sa reference : le demandeur n'a pas de compte, et
// sans cette page il n'avait aucun moyen de savoir ou en etait sa demande.
$router->guest('GET', '/demande-etablissement/suivi', [PublicController::class, 'trackEstablishmentRequest']);
$router->guest('POST', '/demande-etablissement/suivi', [PublicController::class, 'trackEstablishmentRequest']);

$router->guest('GET', '/bulletins/verification', [BulletinController::class, 'verify']);

// Page de repli du mode hors-ligne. Mise en cache des l'installation du service
// worker : elle doit rester affichable precisement quand plus rien ne l'est.
$router->guest('GET', '/hors-ligne', static function (Request $request, Application $app): Response {
    return Response::html($app->view()->render('public.offline', []));
});

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

// Changement de mot de passe obligatoire apres un mot de passe provisoire.
$router->get('/mot-de-passe/changer', [AuthController::class, 'showChangePassword']);
$router->post('/mot-de-passe/changer', [AuthController::class, 'changePassword']);

// Double authentification (TOTP), proposee aux roles a privileges.
$router->get('/mfa/enroler', [AuthController::class, 'showMfaEnroll']);
$router->post('/mfa/enroler', [AuthController::class, 'enrollMfa']);
$router->post('/mfa/enroler/plus-tard', [AuthController::class, 'dismissMfaEnroll']);

$router->get('/dashboard', [DashboardController::class, 'index']);

// Module 4 : eleves
$router->get('/students', [StudentController::class, 'index'], 'students:read');
$router->get('/students/create', [StudentController::class, 'create'], 'students:create');
$router->post('/students', [StudentController::class, 'store'], 'students:create');
$router->get('/students/{id}', [StudentController::class, 'show'], 'students:read');
$router->get('/students/{id}/certificate', [StudentController::class, 'certificate'], 'students:read');
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

// Module 6 : bulletins
$router->get('/bulletins', [BulletinController::class, 'index'], 'bulletins:read');
$router->get('/bulletins/{id}', [BulletinController::class, 'show'], 'bulletins:read');
$router->post('/bulletins/generate/{classroom}/{period}', [BulletinController::class, 'generate'], 'bulletins:generate');
$router->post('/bulletins/publish/{classroom}/{period}', [BulletinController::class, 'publish'], 'bulletins:send');

// Module 9 : emplois du temps
$router->get('/timetable', [SchoolLifeController::class, 'timetable'], 'timetables:read', 'life.timetable');
$router->post('/timetable/{classroom}', [SchoolLifeController::class, 'storeSlot'], 'timetables:create', 'life.timetable');
$router->post('/timetable/{id}/delete', [SchoolLifeController::class, 'deleteSlot'], 'timetables:delete', 'life.timetable');

// Module 10 : presences
$router->get('/attendance', [SchoolLifeController::class, 'attendance'], 'attendance:read', 'life.attendance');
$router->post('/attendance/{classroom}', [SchoolLifeController::class, 'storeAttendance'], 'attendance:create', 'life.attendance');

// Module 11 : discipline
$router->get('/discipline', [SchoolLifeController::class, 'discipline'], 'discipline:read', 'life.discipline');
$router->post('/discipline', [SchoolLifeController::class, 'storeIncident'], 'discipline:create', 'life.discipline');

// Module 8bis : cahier de textes numerique
$router->get('/course-log', [CourseLogController::class, 'index'], 'course-log:read', 'life.textbook');
$router->post('/course-log', [CourseLogController::class, 'store'], 'course-log:create', 'life.textbook');
$router->post('/course-log/{id}', [CourseLogController::class, 'update'], 'course-log:update', 'life.textbook');

// Module 12 : sante scolaire
$router->get('/health', [HealthController::class, 'index'], 'health:read', 'life.health');
$router->get('/health/{id}', [HealthController::class, 'show'], 'health:read', 'life.health');
$router->post('/health/{id}', [HealthController::class, 'save'], 'health:create', 'life.health');

// Module 14 : bibliotheque
$router->get('/library', [LibraryController::class, 'index'], 'library:read', 'life.library');
$router->post('/library/books', [LibraryController::class, 'storeBook'], 'library:create', 'life.library');
$router->post('/library/books/{id}/borrow', [LibraryController::class, 'borrow'], 'library:update', 'life.library');
$router->post('/library/borrows/{id}/return', [LibraryController::class, 'returnBook'], 'library:update', 'life.library');

// Modules 15 a 17 : transport, cantine, patrimoine
$router->get('/transport', [LogisticsController::class, 'transport'], 'transport:read', 'life.transport');
$router->post('/transport/vehicles', [LogisticsController::class, 'storeVehicle'], 'transport:create', 'life.transport');
$router->post('/transport/routes', [LogisticsController::class, 'storeRoute'], 'transport:create', 'life.transport');
$router->post('/transport/routes/{id}/subscribe', [LogisticsController::class, 'subscribe'], 'transport:create', 'life.transport');
$router->get('/catering', [LogisticsController::class, 'catering'], 'catering:read', 'life.catering');
$router->post('/catering', [LogisticsController::class, 'storeMenu'], 'catering:create', 'life.catering');
$router->get('/patrimoine', [LogisticsController::class, 'assets'], 'assets:read', 'life.assets');
$router->post('/patrimoine', [LogisticsController::class, 'storeAsset'], 'assets:create', 'life.assets');
$router->post('/patrimoine/{id}/maintenance', [LogisticsController::class, 'storeMaintenance'], 'assets:update', 'life.assets');

// Module 18 : ressources humaines
$router->get('/hr', [HrController::class, 'index'], 'hr:read', 'hr.payroll');
$router->post('/hr/employees', [HrController::class, 'storeEmployee'], 'hr:create', 'hr.payroll');
$router->post('/hr/leaves', [HrController::class, 'storeLeave'], 'hr:create', 'hr.payroll');
$router->post('/hr/leaves/{id}', [HrController::class, 'decideLeave'], 'hr:update', 'hr.leaves');

// Module 22 : comptabilite generale et budget.
// Saisir et valider portent des permissions distinctes : la validation rend
// l'ecriture definitive, et c'est le seul geste qu'un controle regardera.
$router->get('/comptabilite', [AccountingController::class, 'index'], 'accounting:read', 'finance.accounting');
$router->get('/comptabilite/ecritures/{id}', [AccountingController::class, 'show'], 'accounting:read', 'finance.accounting');
$router->post('/comptabilite/comptes', [AccountingController::class, 'storeAccount'], 'accounting:create', 'finance.accounting');
$router->post('/comptabilite/plan', [AccountingController::class, 'seedChart'], 'accounting:create', 'finance.accounting');
$router->post('/comptabilite/ecritures', [AccountingController::class, 'storeEntry'], 'accounting:create', 'finance.accounting');
$router->post('/comptabilite/ecritures/{id}/valider', [AccountingController::class, 'postEntry'], 'accounting:post', 'finance.accounting');
$router->post('/comptabilite/ecritures/{id}/contrepasser', [AccountingController::class, 'reverseEntry'], 'accounting:post', 'finance.accounting');
$router->post('/comptabilite/budget', [AccountingController::class, 'storeBudget'], 'budget:create', 'finance.budget');

// Module 23 : achats et fournisseurs.
$router->get('/achats', [ProcurementController::class, 'index'], 'purchases:read', 'purchase.orders');
$router->get('/achats/{id}', [ProcurementController::class, 'show'], 'purchases:read', 'purchase.orders');
$router->post('/achats/fournisseurs', [ProcurementController::class, 'storeSupplier'], 'suppliers:create', 'purchase.orders');
$router->post('/achats/commandes', [ProcurementController::class, 'storeOrder'], 'purchases:create', 'purchase.orders');
$router->post('/achats/{id}/decision', [ProcurementController::class, 'decide'], 'purchases:approve', 'purchase.orders');
$router->post('/achats/{id}/reception', [ProcurementController::class, 'receive'], 'purchases:receive', 'purchase.orders');

// Module 24 : stocks et magasin.
$router->get('/stocks', [StockController::class, 'index'], 'stock:read', 'stock.items');
$router->get('/stocks/{id}', [StockController::class, 'show'], 'stock:read', 'stock.items');
$router->post('/stocks/articles', [StockController::class, 'storeItem'], 'stock:create', 'stock.items');
$router->post('/stocks/{id}/mouvement', [StockController::class, 'move'], 'stock:move', 'stock.items');

// Module 42 : paie.
$router->get('/paie', [PayrollController::class, 'index'], 'payroll:read', 'hr.payslips');
$router->get('/paie/bulletins/{id}', [PayrollController::class, 'show'], 'payroll:read', 'hr.payslips');
$router->post('/paie/periodes', [PayrollController::class, 'storePeriod'], 'payroll:create', 'hr.payslips');
$router->post('/paie/periodes/{id}/generer', [PayrollController::class, 'generate'], 'payroll:create', 'hr.payslips');
$router->post('/paie/periodes/{id}/clore', [PayrollController::class, 'close'], 'payroll:close', 'hr.payslips');
$router->post('/paie/bulletins/{id}/valider', [PayrollController::class, 'validateSlip'], 'payroll:close', 'hr.payslips');

// Module 37 : gestion electronique des documents.
// Le telechargement passe par le controleur, jamais par un fichier statique :
// un chemin devinable ne doit pas suffire a lire une piece.
$router->get('/documents', [DocumentController::class, 'index'], 'documents:read', 'ged.documents');
$router->get('/documents/{id}/telecharger', [DocumentController::class, 'download'], 'documents:read', 'ged.documents');
$router->post('/documents', [DocumentController::class, 'store'], 'documents:create', 'ged.documents');
$router->post('/documents/{id}/retirer', [DocumentController::class, 'destroy'], 'documents:delete', 'ged.documents');

// Module 39 : rendez-vous.
$router->get('/rendez-vous', [AppointmentController::class, 'index'], 'appointments:read', 'life.appointments');
$router->post('/rendez-vous', [AppointmentController::class, 'store'], 'appointments:create', 'life.appointments');
$router->post('/rendez-vous/{id}/statut', [AppointmentController::class, 'updateStatus'], 'appointments:update', 'life.appointments');

// Module 40 : administration publique — actes de carriere et notes de service.
$router->get('/administration', [PublicAdministrationController::class, 'index'], 'staff-decisions:read', 'admin.public_acts');
$router->get('/administration/actes/{id}', [PublicAdministrationController::class, 'showDecision'], 'staff-decisions:read', 'admin.public_acts');
$router->post('/administration/actes', [PublicAdministrationController::class, 'storeDecision'], 'staff-decisions:create', 'admin.public_acts');
$router->post('/administration/actes/{id}/signer', [PublicAdministrationController::class, 'signDecision'], 'staff-decisions:sign', 'admin.public_acts');
$router->post('/administration/notes', [PublicAdministrationController::class, 'storeNote'], 'staff-decisions:create', 'admin.public_acts');
$router->post('/administration/notes/{id}/publier', [PublicAdministrationController::class, 'publishNote'], 'staff-decisions:sign', 'admin.public_acts');

// Module 43 : objectifs et performances.
$router->get('/objectifs', [ObjectiveController::class, 'index'], 'objectives:read', 'pilot.objectives');
$router->post('/objectifs', [ObjectiveController::class, 'store'], 'objectives:create', 'pilot.objectives');
$router->post('/objectifs/{id}/releve', [ObjectiveController::class, 'record'], 'objectives:update', 'pilot.objectives');
$router->post('/objectifs/{id}/statut', [ObjectiveController::class, 'updateStatus'], 'objectives:update', 'pilot.objectives');

// Module 8 : communication
$router->get('/communication', [CommunicationController::class, 'index'], 'communications:read');
$router->post('/communication/send', [CommunicationController::class, 'send'], 'communications:create');
$router->post('/communication/templates', [CommunicationController::class, 'storeTemplate'], 'communication-templates:create');
$router->post('/communication/system-templates', [CommunicationController::class, 'storeSystemTemplate'], 'communication-templates:create');
$router->get('/messages', [CommunicationController::class, 'inbox'], 'internal-messages:read');

// Examens officiels
$router->get('/exams', [ExamController::class, 'index'], 'exams:read', 'exams.official');
$router->post('/exams', [ExamController::class, 'store'], 'exams:create', 'exams.official');
$router->get('/exams/{id}', [ExamController::class, 'show'], 'exams:read', 'exams.official');
$router->post('/exams/{id}/register', [ExamController::class, 'register'], 'exams:register', 'exams.official');
$router->post('/exams/registrations/{id}', [ExamController::class, 'updateRegistration'], 'exams:register', 'exams.official');

// Parametres de l etablissement : activation des fonctionnalites optionnelles.
// Annee scolaire et periodes de saisie. Tout en depend : sans annee active
// aucune inscription, sans periode ouverte ni note ni appel.
$router->get('/annees-scolaires', [AcademicYearController::class, 'index'], 'academic-years:read');
$router->post('/annees-scolaires', [AcademicYearController::class, 'store'], 'academic-years:create');
$router->post('/annees-scolaires/{id}/activer', [AcademicYearController::class, 'activate'], 'academic-years:update');
$router->post('/periodes/{id}/ouvrir', [AcademicYearController::class, 'openPeriod'], 'academic-years:update');
$router->post('/periodes/{id}/fermer', [AcademicYearController::class, 'closePeriod'], 'academic-years:update');

$router->get('/parametres', [SettingsController::class, 'index'], 'tenants:read');
$router->post('/parametres', [SettingsController::class, 'save'], 'tenants:update');

// --- Administration de la plateforme --------------------------------------
// Reserve au Super Admin, qui n'appartient a aucun etablissement. A ne pas
// confondre avec l'administrateur d'une ecole, souvent le directeur, dont les
// droits s'arretent a son propre etablissement.
$router->get('/admin', [PlatformController::class, 'dashboard'], 'tenants:read');
$router->post('/admin/etablissements/{id}/consulter', [PlatformController::class, 'enter'], 'tenants:read');
$router->post('/admin/quitter', [PlatformController::class, 'leave'], 'tenants:read');

// Parc d'etablissements : creation directe, fiche, suspension, retrait.
// Les controleurs verifient eux-memes le role Super Admin, ces ecrans ne
// portant sur aucun etablissement en particulier.
$router->get('/admin/parc', [TenantAdminController::class, 'index'], 'tenants:read');
$router->get('/admin/parc/creer', [TenantAdminController::class, 'createForm'], 'tenants:create');
$router->post('/admin/parc', [TenantAdminController::class, 'store'], 'tenants:create');
$router->get('/admin/parc/{id}/modifier', [TenantAdminController::class, 'editForm'], 'tenants:update');
$router->post('/admin/parc/{id}', [TenantAdminController::class, 'update'], 'tenants:update');
$router->post('/admin/parc/{id}/suspendre', [TenantAdminController::class, 'suspend'], 'tenants:update');
$router->post('/admin/parc/{id}/reactiver', [TenantAdminController::class, 'restore'], 'tenants:update');
$router->post('/admin/parc/{id}/supprimer', [TenantAdminController::class, 'destroy'], 'tenants:delete');

// Journal des courriers : savoir ce qui est reellement parti evite de
// promettre a un directeur des identifiants qu'il n'a jamais recus.
$router->get('/admin/courriers', [TenantAdminController::class, 'notifications'], 'tenants:read');
$router->post('/admin/courriers/{id}/reprendre', [TenantAdminController::class, 'retryNotification'], 'tenants:update');

// Comptes, a l'echelle de la plateforme. Un directeur qui perd son mot de
// passe, un depart a acter, un second administrateur a nommer : ce sont les
// demandes les plus frequentes, et aucune n'etait possible sans ouvrir la base.
$router->get('/admin/comptes', [PlatformUserController::class, 'index'], 'tenants:read');
$router->get('/admin/comptes/creer', [PlatformUserController::class, 'createForm'], 'tenants:create');
$router->post('/admin/comptes', [PlatformUserController::class, 'store'], 'tenants:create');
$router->post('/admin/comptes/{id}/mot-de-passe', [PlatformUserController::class, 'resetPassword'], 'tenants:update');
$router->post('/admin/comptes/{id}/desactiver', [PlatformUserController::class, 'deactivate'], 'tenants:update');
$router->post('/admin/comptes/{id}/activer', [PlatformUserController::class, 'activate'], 'tenants:update');
$router->post('/admin/comptes/{id}/deverrouiller', [PlatformUserController::class, 'unlock'], 'tenants:update');
$router->post('/admin/comptes/{id}/supprimer', [PlatformUserController::class, 'destroy'], 'users:delete');
$router->post('/admin/comptes/{id}/perimetre', [PlatformUserController::class, 'updateScope'], 'users:assign-roles');

// Lecture du parc : comparatif, journal d'audit, habilitations.
$router->get('/admin/rapports', [PlatformReportController::class, 'comparison'], 'tenants:read');
$router->get('/admin/rapports/export', [PlatformReportController::class, 'comparisonCsv'], 'tenants:read');
$router->get('/admin/journal', [PlatformReportController::class, 'auditLog'], 'tenants:read');
$router->get('/admin/habilitations', [PlatformReportController::class, 'roles'], 'tenants:read');

// Maintenance du schema. L'hebergement mutualise n'offre pas de shell
// utilisable : sans cet ecran, une migration se remet a plus tard et le
// schema reste en retard sur le code.
$router->get('/admin/maintenance', [MaintenanceController::class, 'index'], 'tenants:update');
$router->post('/admin/maintenance/migrer', [MaintenanceController::class, 'migrate'], 'tenants:update');

// Demandes de creation d'etablissement.
// Le controleur verifie lui-meme le role Super Admin, ces demandes n'etant
// rattachees a aucun etablissement.
$router->get('/admin/etablissements', [EstablishmentRequestController::class, 'index'], 'tenants:read');
$router->post('/admin/etablissements/{id}/approuver', [EstablishmentRequestController::class, 'approve'], 'tenants:create');
$router->post('/admin/etablissements/{id}/refuser', [EstablishmentRequestController::class, 'reject'], 'tenants:update');
