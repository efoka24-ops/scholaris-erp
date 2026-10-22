import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { higherRoutes } from '@/views/higher/routes';
import { higherGradesRoutes } from '@/views/higher/grades/routes';
import { higherTeachingRoutes } from '@/views/higher/teaching/routes';
import { higherDocumentsRoutes } from '@/views/higher/documents/routes';
import { higherPortalRoutes } from '@/views/higher/portals/routes';
import { higherTeachingExtraRoutes } from '@/views/higher/teaching-extra/routes';
import { higherSittingRoutes } from '@/views/higher/sitting/routes';
import { higherInternshipsRoutes } from '@/views/higher/internships/routes';
import { higherVacationsRoutes } from '@/views/higher/vacations/routes';
import { higherNationalExamsRoutes } from '@/views/higher/national-exams/routes';
import { higherDashboardsRoutes } from '@/views/higher/dashboards/routes';
import { higherHelpRoutes } from '@/views/higher/help/routes';
import { higherTutelageRoutes } from '@/views/higher/tutelage/routes';
import { higherDeliberationRoutes } from '@/views/higher/deliberation/routes';
import { accountRoutes } from '@/views/account/routes';
import { publicSchoolsRoutes } from '@/views/public-schools/routes';
import { nurseryPrivateRoutes } from '@/views/nursery-private/routes';
import { primaryRoutes } from '@/views/primary/routes';
import { importRoutes } from '@/views/admin/import/routes';
import { workflowRoutes } from '@/views/admin/workflows/routes';

declare module 'vue-router' {
  interface RouteMeta {
    /** Accessible sans connexion. */
    public?: boolean;
    /** Autorisé si la personne détient au moins une de ces permissions. */
    perms?: string[];
    /** Réservé à ces rôles (portails famille et apprenant). */
    roles?: string[];
    /** Entrée de menu réservée aux établissements de ces types (ex. nursery). */
    establishmentTypes?: string[];
    /** Exige un établissement actif (en-tête X-Establishment-Id). */
    needsEstablishment?: boolean;
    /** Écran sans cadre (connexion, double authentification). */
    bare?: boolean;
    /** Écran de l'administrateur de plateforme : seul visible pour un compte du client technique `platform`, jamais pour un compte client. */
    platform?: boolean;
    titleKey?: MessageKey;
    nav?: { labelKey: MessageKey; order: number };
  }
}

export const routes: RouteRecordRaw[] = [
  // Page publique de vérification d'un document (lien du code QR) : sans connexion ni cadre.
  { path: '/verification/:token?', name: 'verify', component: () => import('@/views/VerificationView.vue'), props: true, meta: { public: true, bare: true, titleKey: 'verify.title' } },
  { path: '/connexion', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true, bare: true, titleKey: 'login.title' } },
  { path: '/activation', name: 'activation', component: () => import('@/views/ActivationView.vue'), meta: { public: true, bare: true, titleKey: 'activation.title' } },
  { path: '/mot-de-passe-oublie', name: 'reset', component: () => import('@/views/ActivationView.vue'), props: { mode: 'reset' }, meta: { public: true, bare: true, titleKey: 'activation.resetTitle' } },
  // Pages publiques de la plateforme (sans connexion) : accueil, catalogue, demande d'accès et suivi par jeton.
  { path: '/decouvrir', name: 'public-home', component: () => import('@/views/platform/PublicHomeView.vue'), meta: { public: true, bare: true, titleKey: 'pub.home.title' } },
  { path: '/applications-disponibles', name: 'public-catalog', component: () => import('@/views/platform/PublicCatalogView.vue'), meta: { public: true, bare: true, titleKey: 'pub.catalog.title' } },
  { path: '/demande-acces', name: 'access-request', component: () => import('@/views/platform/AccessRequestView.vue'), meta: { public: true, bare: true, titleKey: 'pub.req.title' } },
  { path: '/demande-acces/:token', name: 'access-tracking', component: () => import('@/views/platform/AccessTrackingView.vue'), props: true, meta: { public: true, bare: true, titleKey: 'pub.track.title' } },
  { path: '/double-authentification', name: 'twofactor', component: () => import('@/views/TwoFactorView.vue'), meta: { bare: true, titleKey: 'twofa.title' } },
  { path: '/double-authentification/activation', name: 'twofactor-setup', component: () => import('@/views/TwoFactorSetupView.vue'), meta: { bare: true, titleKey: 'twofa.setupTitle' } },
  { path: '/', name: 'home', component: () => import('@/views/HomeView.vue'), meta: { titleKey: 'home.title', nav: { labelKey: 'nav.home', order: 0 } } },
  {
    path: '/tableau-de-bord',
    name: 'dashboard',
    component: () => import('@/views/DashboardView.vue'),
    meta: { perms: ['reporting.read', 'reporting.group.read'], titleKey: 'dashboard.title', nav: { labelKey: 'nav.dashboard', order: 10 } },
  },
  {
    path: '/public-primary/registry',
    name: 'public-primary-registry',
    component: () => import('@/apps/public-primary/registry/RegistryView.vue'),
    meta: { perms: ['public_primary.registry.read', 'public_primary.registry.manage'], needsEstablishment: true },
  },
  {
    path: '/appel',
    name: 'attendance',
    component: () => import('@/views/AttendanceView.vue'),
    meta: { perms: ['attendance.take'], needsEstablishment: true, titleKey: 'attendance.title', nav: { labelKey: 'nav.attendance', order: 20 } },
  },
  {
    path: '/maternelle',
    name: 'nursery',
    component: () => import('@/views/nursery/NurseryDashboardView.vue'),
    meta: { perms: ['attendance.read', 'attendance.discipline.manage'], needsEstablishment: true, establishmentTypes: ['nursery'], titleKey: 'nursery.dashTitle', nav: { labelKey: 'nav.nursery', order: 25 } },
  },
  {
    path: '/maternelle/sorties',
    name: 'nursery-pickup',
    component: () => import('@/views/nursery/NurseryPickupView.vue'),
    meta: { perms: ['attendance.read', 'attendance.discipline.manage'], needsEstablishment: true, titleKey: 'nursery.pickupTitle' },
  },
  {
    path: '/maternelle/carnets',
    name: 'nursery-logbook',
    component: () => import('@/views/nursery/NurseryLogbookView.vue'),
    meta: { perms: ['attendance.read', 'attendance.take'], needsEstablishment: true, titleKey: 'nursery.logbookTitle' },
  },
  {
    path: '/maternelle/observations',
    name: 'nursery-observation',
    component: () => import('@/views/public-nursery/ObservationView.vue'),
    meta: { perms: ['public_nursery.dev.observe', 'public_nursery.lifebook.write'], needsEstablishment: true, establishmentTypes: ['nursery'], titleKey: 'pn.observation.title' },
  },
  {
    path: '/maternelle/carnet-trimestriel',
    name: 'nursery-progress-book',
    component: () => import('@/views/public-nursery/ProgressBookView.vue'),
    meta: { perms: ['public_nursery.dev.observe', 'public_nursery.lifebook.write'], needsEstablishment: true, establishmentTypes: ['nursery'], titleKey: 'pn.progressBook.title' },
  },
  {
    path: '/maternelle/cahier-de-vie',
    name: 'nursery-lifebook',
    component: () => import('@/views/public-nursery/LifeBookView.vue'),
    meta: { perms: ['public_nursery.lifebook.write', 'public_nursery.lifebook.view'], needsEstablishment: true, establishmentTypes: ['nursery'], titleKey: 'pn.lifebook.title' },
  },
  {
    path: '/maternelle/transition/decisions',
    name: 'nursery-transition-decisions',
    component: () => import('@/views/public-nursery/TransitionDecisionsView.vue'),
    meta: { perms: ['public_nursery.transition.opinion', 'settings.manage'], needsEstablishment: true, titleKey: 'pn.transition.title' },
  },
  {
    path: '/maternelle/transition/liaison',
    name: 'nursery-transition-liaison',
    component: () => import('@/views/public-nursery/TransitionLiaisonView.vue'),
    meta: { perms: ['public_nursery.transition.opinion', 'settings.manage'], needsEstablishment: true, titleKey: 'pn.liaison.title' },
  },
  {
    path: '/notes',
    name: 'grades',
    component: () => import('@/views/GradesView.vue'),
    meta: { perms: ['assessment.enter', 'assessment.read'], needsEstablishment: true, titleKey: 'grades.title', nav: { labelKey: 'nav.grades', order: 30 } },
  },
  {
    path: '/bulletins',
    name: 'report-cards',
    component: () => import('@/views/ReportCardsView.vue'),
    meta: { perms: ['assessment.read'], needsEstablishment: true, titleKey: 'reportcards.title', nav: { labelKey: 'nav.reportcards', order: 35 } },
  },
  {
    path: '/emploi-du-temps',
    name: 'timetable',
    component: () => import('@/views/TimetableView.vue'),
    meta: { perms: ['scheduling.read', 'attendance.take'], needsEstablishment: true, titleKey: 'timetable.title', nav: { labelKey: 'nav.timetable', order: 40 } },
  },
  {
    path: '/personnes',
    name: 'people',
    component: () => import('@/views/PeopleView.vue'),
    meta: { perms: ['people.read'], needsEstablishment: true, titleKey: 'people.title', nav: { labelKey: 'nav.people', order: 50 } },
  },
  {
    path: '/inscription',
    name: 'enroll',
    component: () => import('@/views/EnrollmentWizardView.vue'),
    meta: { perms: ['people.manage'], needsEstablishment: true, titleKey: 'enroll.title' },
  },
  {
    path: '/personnes/:id',
    name: 'person',
    component: () => import('@/views/PersonView.vue'),
    props: true,
    meta: { perms: ['people.read'], needsEstablishment: true, titleKey: 'people.title' },
  },
  {
    path: '/facturation',
    name: 'billing',
    component: () => import('@/views/BillingView.vue'),
    meta: { perms: ['billing.read', 'finance.read'], needsEstablishment: true, titleKey: 'billing.title', nav: { labelKey: 'nav.billing', order: 60 } },
  },
  {
    path: '/messages',
    name: 'messages',
    component: () => import('@/views/MessagesView.vue'),
    meta: { perms: ['messaging.read', 'self.read'], needsEstablishment: true, titleKey: 'messages.title', nav: { labelKey: 'nav.messages', order: 70 } },
  },
  {
    path: '/documents',
    name: 'documents',
    component: () => import('@/views/DocumentsView.vue'),
    meta: { perms: ['documents.read', 'documents.request'], needsEstablishment: true, titleKey: 'documents.title', nav: { labelKey: 'nav.documents', order: 80 } },
  },
  {
    path: '/portail/famille',
    name: 'portal-family',
    component: () => import('@/views/FamilyPortalView.vue'),
    meta: { roles: ['parent'], titleKey: 'family.title', nav: { labelKey: 'nav.family', order: 5 } },
  },
  {
    path: '/portail/apprenant',
    name: 'portal-learner',
    component: () => import('@/views/LearnerPortalView.vue'),
    meta: { roles: ['apprenant'], titleKey: 'learner.title', nav: { labelKey: 'nav.learner', order: 5 } },
  },
  {
    path: '/portail/enseignant',
    name: 'portal-teacher',
    component: () => import('@/views/teacher/TeacherPortalView.vue'),
    meta: { roles: ['enseignant'], titleKey: 'teacher.title', nav: { labelKey: 'nav.teacher', order: 5 } },
  },
  {
    path: '/personnel',
    name: 'staff',
    component: () => import('@/views/staff/StaffListView.vue'),
    meta: { perms: ['staff.read'], needsEstablishment: true, titleKey: 'staff.title', nav: { labelKey: 'nav.staff', order: 55 } },
  },
  {
    path: '/personnel/vacations',
    name: 'vacations',
    component: () => import('@/views/staff/VacationsView.vue'),
    // Un enseignant (sans droit de lecture du personnel) y retrouve ses propres heures, contrats et relevés.
    meta: { perms: ['staff.read', 'vacations.manage', 'vacations.validate', 'staff.leave.request'], needsEstablishment: true, titleKey: 'vac.title', nav: { labelKey: 'nav.vacations', order: 56 } },
  },
  {
    path: '/personnel/conges',
    name: 'leave',
    component: () => import('@/views/staff/LeaveView.vue'),
    meta: { perms: ['staff.leave.request', 'staff.leave.approve', 'staff.manage'], needsEstablishment: true, titleKey: 'leave.title', nav: { labelKey: 'nav.leave', order: 57 } },
  },
  {
    path: '/personnel/:id',
    name: 'staff-member',
    component: () => import('@/views/staff/StaffView.vue'),
    props: true,
    meta: { perms: ['staff.read'], needsEstablishment: true, titleKey: 'staff.title' },
  },
  {
    path: '/rapports',
    name: 'reports',
    component: () => import('@/views/reports/ReportsView.vue'),
    meta: { perms: ['reporting.manage'], needsEstablishment: true, titleKey: 'reports.title', nav: { labelKey: 'nav.reports', order: 85 } },
  },
  {
    path: '/rapports/statistiques',
    name: 'regulatory',
    component: () => import('@/views/reports/RegulatoryView.vue'),
    meta: { perms: ['reporting.read'], needsEstablishment: true, titleKey: 'regstats.title', nav: { labelKey: 'nav.regstats', order: 86 } },
  },
  {
    path: '/confidentialite',
    name: 'privacy',
    component: () => import('@/views/PrivacyView.vue'),
    meta: { perms: ['privacy.requests.read', 'self.read'], titleKey: 'privacy.title', nav: { labelKey: 'nav.privacy', order: 90 } },
  },
  {
    path: '/securite',
    name: 'security',
    component: () => import('@/views/SecurityView.vue'),
    meta: { titleKey: 'security.title', nav: { labelKey: 'nav.security', order: 95 } },
  },
  {
    path: '/synchronisation',
    name: 'sync',
    component: () => import('@/views/SyncView.vue'),
    meta: { titleKey: 'sync.title', nav: { labelKey: 'nav.sync', order: 99 } },
  },
  // Administration : le super administrateur sans établissement n'a que ces écrans (voir stores/context.ts : tenantAdmin).
  {
    path: '/administration',
    name: 'admin',
    component: () => import('@/views/admin/AdminHomeView.vue'),
    meta: { perms: ['organisation.manage', 'identity.manage', 'settings.manage'], titleKey: 'admin.title', nav: { labelKey: 'nav.admin', order: 3 } },
  },
  {
    path: '/administration/etablissements',
    name: 'admin-establishments',
    component: () => import('@/views/admin/AdminEstablishmentsView.vue'),
    meta: { perms: ['organisation.manage'], titleKey: 'admin.nav.establishments' },
  },
  {
    path: '/administration/etablissements/:id',
    name: 'admin-establishment',
    component: () => import('@/views/admin/AdminEstablishmentView.vue'),
    props: true,
    meta: { perms: ['organisation.manage'], titleKey: 'admin.nav.establishments' },
  },
  {
    path: '/administration/annees',
    name: 'admin-years',
    component: () => import('@/views/admin/AdminYearsView.vue'),
    meta: { perms: ['organisation.manage'], needsEstablishment: true, titleKey: 'admin.nav.years' },
  },
  {
    path: '/administration/structures',
    name: 'admin-structures',
    component: () => import('@/views/admin/AdminStructuresView.vue'),
    meta: { perms: ['organisation.manage'], needsEstablishment: true, titleKey: 'admin.nav.structures' },
  },
  {
    path: '/administration/utilisateurs',
    name: 'admin-users',
    component: () => import('@/views/admin/AdminUsersView.vue'),
    meta: { perms: ['identity.manage', 'identity.roles.assign'], needsEstablishment: true, titleKey: 'admin.nav.users' },
  },
  {
    path: '/administration/parametres',
    name: 'admin-settings',
    component: () => import('@/views/admin/AdminSettingsView.vue'),
    meta: { perms: ['settings.manage'], needsEstablishment: true, titleKey: 'admin.nav.settings' },
  },
  // Applications du client (abonnements) : lecture pour qui administre le client ; ouvrir et payer exigent applications.manage.
  {
    path: '/applications',
    name: 'applications',
    component: () => import('@/views/platform/MyApplicationsView.vue'),
    meta: { perms: ['applications.manage', 'organisation.manage'], titleKey: 'apps.title', nav: { labelKey: 'nav.applications', order: 4 } },
  },
  // Administrateur de plateforme (client technique `platform`) : aucun en-tête X-Establishment-Id, aucune donnée métier.
  {
    path: '/platform',
    name: 'platform-dashboard',
    component: () => import('@/views/platform/PlatformDashboardView.vue'),
    meta: { platform: true, titleKey: 'plat.dash.title', nav: { labelKey: 'nav.platform.dashboard', order: 1 } },
  },
  {
    path: '/platform/demandes',
    name: 'platform-requests',
    component: () => import('@/views/platform/PlatformRequestsView.vue'),
    meta: { platform: true, titleKey: 'plat.req.title', nav: { labelKey: 'nav.platform.requests', order: 2 } },
  },
  {
    path: '/platform/demandes/:id',
    name: 'platform-request',
    component: () => import('@/views/platform/PlatformRequestView.vue'),
    props: true,
    meta: { platform: true, titleKey: 'plat.req.title' },
  },
  {
    path: '/platform/clients',
    name: 'platform-clients',
    component: () => import('@/views/platform/PlatformClientsView.vue'),
    meta: { platform: true, titleKey: 'plat.cli.title', nav: { labelKey: 'nav.platform.clients', order: 3 } },
  },
  {
    path: '/platform/clients/:tenant',
    name: 'platform-client',
    component: () => import('@/views/platform/PlatformClientView.vue'),
    props: true,
    meta: { platform: true, titleKey: 'plat.cli.title' },
  },
  {
    path: '/platform/catalogue',
    name: 'platform-catalog',
    component: () => import('@/views/platform/PlatformCatalogView.vue'),
    meta: { platform: true, titleKey: 'plat.cat.title', nav: { labelKey: 'nav.platform.catalog', order: 4 } },
  },
  {
    path: '/platform/paiements',
    name: 'platform-payments',
    component: () => import('@/views/platform/PlatformPaymentsView.vue'),
    meta: { platform: true, titleKey: 'plat.pay.title', nav: { labelKey: 'nav.platform.payments', order: 5 } },
  },
  {
    path: '/platform/administrateurs',
    name: 'platform-admins',
    component: () => import('@/views/platform/PlatformAdminsView.vue'),
    meta: { platform: true, titleKey: 'pa.adm.platformTitle', nav: { labelKey: 'pa.nav.admins', order: 6 } },
  },
  {
    path: '/platform/journal',
    name: 'platform-audit',
    component: () => import('@/views/platform/PlatformAuditView.vue'),
    meta: { platform: true, titleKey: 'pa.audit.title', nav: { labelKey: 'pa.nav.audit', order: 7 } },
  },
  {
    path: '/platform/reglages',
    name: 'platform-settings',
    component: () => import('@/views/platform/PlatformSettingsView.vue'),
    meta: { platform: true, titleKey: 'pa.set.title', nav: { labelKey: 'pa.nav.settings', order: 8 } },
  },
  ...higherRoutes,
  ...higherGradesRoutes,
  ...higherTeachingRoutes,
  ...higherDocumentsRoutes,
  ...higherPortalRoutes,
  ...higherTeachingExtraRoutes,
  ...higherSittingRoutes,
  ...higherInternshipsRoutes,
  ...higherVacationsRoutes,
  ...higherNationalExamsRoutes,
  ...higherDashboardsRoutes,
  ...higherHelpRoutes,
  ...higherTutelageRoutes,
  ...higherDeliberationRoutes,
  ...accountRoutes,
  ...publicSchoolsRoutes,
  ...nurseryPrivateRoutes,
  ...primaryRoutes,
  ...importRoutes,
  ...workflowRoutes,
  { path: '/interdit', name: 'forbidden', component: () => import('@/views/ForbiddenView.vue'), meta: { titleKey: 'forbidden.title' } },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { public: true, titleKey: 'notFound.title' } },
];
