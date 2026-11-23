// Routes « Notes » de l'application IPES (module NOT) : réservées aux établissements higher, gardées par higherGuard.
// À relier dans router/routes.ts (…higherGradesRoutes). titleKey reste 'nav.higher' tant que les clés hg.* ne sont pas
// fusionnées dans fr.ts / en.ts (MessageKey).
import type { RouteRecordRaw } from 'vue-router';
import { higherGuard } from '../access';
import { G } from './perms';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], withProps?: boolean];

const screens: Screen[] = [
  ['notes', 'higher-grades', () => import('./AssessmentsView.vue'), G.read],
  ['notes/evaluations/:id', 'higher-grades-grid', () => import('./AssessmentGridView.vue'), G.read, true],
  ['notes/evaluations/:id/statistiques', 'higher-grades-stats', () => import('./StatisticsView.vue'), G.read, true],
  ['notes/ec/:elementId/statistiques', 'higher-grades-element-stats', () => import('./StatisticsView.vue'), G.read, true],
  ['notes/suivi', 'higher-grades-progress', () => import('./ProgressView.vue'), G.progress],
  ['notes/corrections', 'higher-grades-corrections', () => import('./CorrectionsView.vue'), G.corrections],
  ['notes/requetes', 'higher-grades-queries', () => import('./QueriesView.vue'), G.queries],
];

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherGradesRoutes: RouteRecordRaw[] = [
  ...screens.map(([path, name, view, perms, withProps]): RouteRecordRaw => ({
    path: `/superieur/${path}`,
    name,
    component: view,
    props: withProps === true,
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...perms], titleKey: 'nav.higher' },
  })),
  {
    path: '/superieur/mes-notes',
    name: 'higher-my-grades',
    component: () => import('./MyResultsView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, roles: ['apprenant'], titleKey: 'nav.higher' },
  },
];
