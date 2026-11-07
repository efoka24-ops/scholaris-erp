// Routes des délibérations IPES (DEL) : établissements de type higher, gardées par higherGuard. À ajouter au routeur
// (…higherDeliberationRoutes). Les clés de titre viennent de higher-deliberation-fr.ts (à fusionner dans fr.ts).
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { higherGuard } from '../access';
import { DP } from './access';
import type { DelKey } from './i18n';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], titleKey: DelKey, withProps?: boolean];

const screens: Screen[] = [
  ['deliberation/jurys', 'higher-del-juries', () => import('./JuriesView.vue'), DP.read, 'hdl.jury.title'],
  ['deliberation/jurys/:id', 'higher-del-jury', () => import('./JuryView.vue'), DP.read, 'hdl.jury.title', true],
  ['deliberation/jurys/:id/preparation', 'higher-del-preparation', () => import('./PreparationView.vue'), DP.prepareOrPreside, 'hdl.prep.title', true],
  ['deliberation/jurys/:id/rattrapage', 'higher-del-resit', () => import('./ResitView.vue'), DP.prepareOrPreside, 'hdl.resit.title', true],
  ['deliberation/jurys/:id/documents', 'higher-del-documents', () => import('./DocumentsView.vue'), DP.prepareOrPreside, 'hdl.doc.title', true],
  ['deliberation/decisions-annuelles', 'higher-del-years', () => import('./YearDecisionsView.vue'), DP.read, 'hdl.year.title'],
  ['deliberation/releves', 'higher-del-transcripts', () => import('./TranscriptsView.vue'), DP.read, 'hdl.tr.title'],
  ['deliberation/mes-jurys', 'higher-del-portal', () => import('./JuryPortalView.vue'), DP.portal, 'hdl.portal.title'],
];

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherDeliberationRoutes: RouteRecordRaw[] = [
  ...screens.map(([path, name, view, perms, titleKey, withProps]): RouteRecordRaw => ({
    path: `/superieur/${path}`,
    name,
    component: view,
    props: withProps === true,
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...perms], titleKey: titleKey as MessageKey },
  })),
  {
    path: '/superieur/deliberation/mes-resultats',
    name: 'higher-del-my-results',
    component: () => import('./MyResultsView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, roles: ['apprenant'], perms: [...DP.self], titleKey: 'hdl.mine.title' as MessageKey },
  },
];
