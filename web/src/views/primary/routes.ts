// Routes de l'école primaire privée (/primaire/*). Réservées aux établissements de type `primary` ET aux permissions primary.* (les
// écoles publiques ont les leurs sous /ecole-publique). Le serveur reste juge des droits ; les permissions adaptent seulement l'accès.
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { P } from './access';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], titleKey: MessageKey];

const screens: Screen[] = [
  ['tableau-de-bord', 'pri-dashboard', () => import('./DashboardView.vue'), P.dashboardRead, 'pri.dash.title'],
  ['niveaux', 'pri-levels', () => import('./LevelsView.vue'), P.referentialRead, 'pri.levels.title'],
  ['referentiel', 'pri-referential', () => import('./ReferentialView.vue'), P.referentialRead, 'pri.ref.title'],
  ['inscriptions', 'pri-desk', () => import('./DeskView.vue'), P.learnerRead, 'pri.desk.title'],
  ['appel', 'pri-call', () => import('./CallView.vue'), P.attendance, 'pri.call.title'],
  ['competences', 'pri-sheet', () => import('./SheetView.vue'), P.competencyRead, 'pri.sheet.title'],
  ['cahier', 'pri-journal', () => import('./JournalView.vue'), P.journalRead, 'pri.journal.title'],
  ['conduite', 'pri-conduct', () => import('./ConductView.vue'), P.conductRead, 'pri.conduct.title'],
  ['conseils', 'pri-council', () => import('./CouncilView.vue'), P.councilRead, 'pri.council.title'],
  ['bulletins', 'pri-cards', () => import('./ReportCardsView.vue'), P.reportCardRead, 'pri.cards.title'],
  ['remediation', 'pri-remediation', () => import('./RemediationView.vue'), P.remediationRead, 'pri.rem.title'],
  ['examen', 'pri-exam', () => import('./ExamView.vue'), P.examRead, 'pri.exam.title'],
  ['exports', 'pri-exports', () => import('./ExportsView.vue'), P.tutelageExport, 'pri.exports.title'],
  ['cloture', 'pri-closure', () => import('./ClosureView.vue'), P.closureRead, 'pri.close.title'],
  ['famille', 'pri-family', () => import('./FamilyView.vue'), P.family, 'pri.family.title'],
];

const schoolOnly = { needsEstablishment: true, establishmentTypes: ['primary'] };

export const primaryRoutes: RouteRecordRaw[] = [
  {
    path: '/primaire',
    name: 'pri-home',
    component: () => import('./HomeView.vue'),
    meta: { ...schoolOnly, perms: [...P.space], titleKey: 'pri.home.title', nav: { labelKey: 'nav.primary', order: 8 } },
  },
  ...screens.map(([path, name, view, perms, titleKey]): RouteRecordRaw => ({
    path: `/primaire/${path}`,
    name,
    component: view,
    meta: { ...schoolOnly, perms: [...perms], titleKey },
  })),
];
