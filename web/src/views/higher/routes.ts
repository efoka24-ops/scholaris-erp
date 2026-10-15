// Routes de l'application IPES (lot I1) : réservées aux établissements de type higher, gardées par higherGuard.
// Les écrans encore absents (documents, profil documentaire, portails étudiant et garant) n'ont pas de route :
// HigherHomeView et HigherFrame masquent leurs liens tant que router.hasRoute() est faux.
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { higherGuard, P } from './access';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], titleKey: MessageKey, withProps?: boolean];

const screens: Screen[] = [
  ['structure', 'higher-structure', () => import('./AcademicStructureView.vue'), P.settingsRead, 'hi.aca.title'],
  ['autorisations', 'higher-authorizations', () => import('./AuthorizationsView.vue'), P.registersRead, 'hi.auth.title'],
  ['tutelles', 'higher-tutelages', () => import('./TutelagesView.vue'), P.registersRead, 'hi.tut.title'],
  ['reglements', 'higher-regulations', () => import('./RegulationsView.vue'), P.registersRead, 'hi.reg.title'],
  ['calendrier', 'higher-calendar', () => import('./CalendarView.vue'), P.registersRead, 'hi.cal.title'],
  ['maquettes', 'higher-curricula', () => import('./CurriculaView.vue'), P.curriculumRead, 'hi.cur.title'],
  ['maquettes/:id', 'higher-curriculum', () => import('./CurriculumEditorView.vue'), P.curriculumRead, 'hi.cur.title', true],
  ['campagnes', 'higher-campaigns', () => import('./CampaignsView.vue'), P.admissionRead, 'hi.adm.title'],
  ['campagnes/:id', 'higher-campaign', () => import('./CampaignView.vue'), P.admissionRead, 'hi.adm.title', true],
  ['candidatures', 'higher-applications', () => import('./ApplicationsView.vue'), P.admissionRead, 'hi.app.title'],
  ['candidatures/:id', 'higher-application', () => import('./ApplicationView.vue'), P.admissionRead, 'hi.app.title', true],
  ['inscriptions', 'higher-enrolments', () => import('./EnrolmentsView.vue'), P.enrolmentRead, 'hi.ins.title'],
  ['inscriptions/:id', 'higher-enrolment', () => import('./EnrolmentView.vue'), P.enrolmentRead, 'hi.ins.title', true],
  ['etudiants/:personId', 'higher-student', () => import('./StudentView.vue'), P.enrolmentRead, 'hi.stu.title', true],
  ['frais', 'higher-fee-grids', () => import('./FeeGridsView.vue'), P.billingRead, 'hi.fin.gridsTitle'],
  ['comptes', 'higher-accounts', () => import('./AccountsView.vue'), P.billingRead, 'hi.fin.accountsTitle'],
  ['comptes/:id', 'higher-account', () => import('./AccountView.vue'), P.billingRead, 'hi.fin.accountTitle', true],
  ['garants', 'higher-sponsors', () => import('./SponsorsView.vue'), P.billingRead, 'hi.fin.sponsorsTitle'],
];

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherRoutes: RouteRecordRaw[] = [
  {
    path: '/superieur',
    name: 'higher',
    component: () => import('./HigherHomeView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, titleKey: 'hi.home.title', nav: { labelKey: 'nav.higher', order: 6 } },
  },
  ...screens.map(([path, name, view, perms, titleKey, withProps]): RouteRecordRaw => ({
    path: `/superieur/${path}`,
    name,
    component: view,
    props: withProps === true,
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...perms], titleKey },
  })),
];
