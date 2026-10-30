// Routes des écoles publiques (primaire) : personnel d'une école (directeur, enseignant). Le serveur reste juge des droits ;
// les permissions ci-dessous adaptent seulement l'accès aux écrans (un rôle « agrégats seuls » n'en voit aucun).
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { P } from './access';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], titleKey: MessageKey, withProps?: boolean];

const screens: Screen[] = [
  ['appel', 'ps-call', () => import('./CallView.vue'), P.attendanceWrite, 'ps.call.title'],
  ['classes', 'ps-classes', () => import('./ClassesView.vue'), P.pupilsRead, 'ps.classes.title'],
  ['classes/:id/cahier-appel', 'ps-register', () => import('./RegisterView.vue'), P.attendanceRead, 'ps.register.title', true],
  ['eleves', 'ps-pupils', () => import('./PupilsView.vue'), P.pupilsRead, 'ps.pupils.title'],
  ['eleves/inscription', 'ps-enrol', () => import('./EnrolPupilView.vue'), P.pupilsWrite, 'ps.enrol.title'],
  ['eleves/:id', 'ps-pupil', () => import('./PupilView.vue'), P.pupilsRead, 'ps.pupil.title', true],
  ['transferts', 'ps-transfers', () => import('./TransfersView.vue'), P.pupilsWrite, 'ps.transfers.title'],
  ['abandons', 'ps-dropouts', () => import('./DropoutsView.vue'), P.pupilsWrite, 'ps.dropouts.title'],
  ['ecole', 'ps-school', () => import('./SchoolView.vue'), P.schoolRead, 'ps.school.title'],
  ['ecole/infrastructure/:id', 'ps-rooms', () => import('./RoomsView.vue'), P.schoolRead, 'ps.rooms.title', true],
  ['ecole/photos', 'ps-photos', () => import('./PhotosView.vue'), P.inspectionRead, 'ps.photos.title'],
  ['presence-enseignants', 'ps-presence', () => import('./TeacherPresenceView.vue'), P.presenceRead, 'ps.presence.title'],
  ['controle-presence', 'ps-control', () => import('./ControlView.vue'), P.presenceValidate, 'ps.control.title'],
  ['alertes', 'ps-alerts', () => import('./AlertsView.vue'), P.alerts, 'ps.alerts.title'],
  ['personnel', 'ps-staff', () => import('./StaffView.vue'), P.staffRead, 'ps.staff.title'],
  ['personnel/:id', 'ps-staff-profile', () => import('./StaffProfileView.vue'), P.staffRead, 'ps.career.title', true],
  ['messages', 'ps-messages', () => import('./MessagesView.vue'), P.messagesSend, 'ps.messages.title'],
  ['appareil', 'ps-device', () => import('./DeviceView.vue'), P.attendanceWrite, 'ps.device.title'],
  // Lot P2 : évaluation, remédiation, bulletins, passage, APEE, paquet minimum, questionnaire statistique, inscription groupée, référentiel.
  ['evaluation', 'pp-evaluation', () => import('./EvaluationView.vue'), P.evaluation, 'pp.eval.title'],
  ['remediation', 'pp-remediation', () => import('./RemediationView.vue'), P.evaluation, 'pp.rem.title'],
  ['bulletins', 'pp-report-cards', () => import('./ReportCardsView.vue'), P.evaluation, 'pp.cards.title'],
  ['passage', 'pp-promotion', () => import('./PromotionView.vue'), P.promotion, 'pp.pas.title'],
  ['apee', 'ps-apee', () => import('./ApeeView.vue'), P.apee, 'pp.apee.title'],
  ['paquet-minimum', 'ps-supplies', () => import('./SuppliesView.vue'), P.schoolRead, 'pp.supplies.title'],
  ['statistiques', 'ps-census', () => import('./CensusView.vue'), P.census, 'pp.census.title'],
  ['eleves/registre', 'ps-batch-enrol', () => import('./BatchEnrolView.vue'), P.pupilsWrite, 'pp.batch.title'],
  ['referentiel', 'pp-referential', () => import('./ReferentialView.vue'), P.referential, 'pp.ref.title'],
  // Lot P3 : examens (école, contrôle, délégation), inspection, cantine et santé scolaire.
  ['examens', 'pp-exams', () => import('./ExamsView.vue'), P.exams, 'pp.exa.title'],
  ['examens/consolidation', 'pp-exams-delegation', () => import('./ExamDelegationView.vue'), P.examsConsolidation, 'pp.exd.title'],
  ['inspection', 'ps-inspection', () => import('./InspectionView.vue'), P.inspection, 'ps.ins.title'],
  ['cantine', 'ps-canteen', () => import('./CanteenView.vue'), P.canteen, 'ps.can.title'],
];

const schoolOnly = { needsEstablishment: true, establishmentTypes: ['primary'] };

export const publicSchoolsRoutes: RouteRecordRaw[] = [
  {
    path: '/ecole-publique',
    name: 'ps-home',
    component: () => import('./HomeView.vue'),
    meta: { ...schoolOnly, perms: [...P.space], titleKey: 'ps.home.title', nav: { labelKey: 'nav.publicSchools', order: 7 } },
  },
  ...screens.map(([path, name, view, perms, titleKey, withProps]): RouteRecordRaw => ({
    path: `/ecole-publique/${path}`,
    name,
    component: view,
    props: withProps === true,
    meta: { ...schoolOnly, perms: [...perms], titleKey },
  })),
  // T690 : tableau de bord territorial (agrégats seuls), sans école imposée : les rôles nationaux et territoriaux y accèdent aussi.
  {
    path: '/tableau-de-bord-territorial',
    name: 'territory-dashboard',
    component: () => import('./DashboardView.vue'),
    meta: { perms: [...P.dashboards], titleKey: 'ps.dash.title' },
  },
];
