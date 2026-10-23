// Routes « Stages et soutenances » de l'application IPES (module STG) : réservées aux établissements higher, gardées par higherGuard.
// À relier dans router/routes.ts (…higherInternshipsRoutes). titleKey reste 'nav.higher' tant que les clés hs.* ne sont pas
// fusionnées dans fr.ts / en.ts (MessageKey).
import type { RouteRecordRaw } from 'vue-router';
import { higherGuard } from '../access';
import { S } from './perms';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], withProps?: boolean];

const screens: Screen[] = [
  ['stages', 'higher-internships', () => import('./ConventionsView.vue'), S.read],
  ['stages/conventions/:id', 'higher-internship', () => import('./ConventionView.vue'), S.read, true],
  ['stages/entreprises', 'higher-internship-companies', () => import('./CompaniesView.vue'), S.read],
  ['stages/soutenances', 'higher-defenses', () => import('./DefensesView.vue'), S.jury],
];

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherInternshipsRoutes: RouteRecordRaw[] = [
  ...screens.map(([path, name, view, perms, withProps]): RouteRecordRaw => ({
    path: `/superieur/${path}`,
    name,
    component: view,
    props: withProps === true,
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...perms], titleKey: 'nav.higher' },
  })),
  {
    path: '/superieur/mon-stage',
    name: 'higher-my-internship',
    component: () => import('./MyInternshipView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, roles: ['apprenant'], titleKey: 'nav.higher' },
  },
];
