// Routes « Vacations » de l'application IPES (EX-ENS-06/07), même forme que ../teaching/routes.ts.
// À relier dans router/routes.ts (...higherVacationsRoutes). Les titres hv.* sont des MessageKey une fois fusionnés dans fr.ts/en.ts.
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import type { HigherVacationsKey } from '@/i18n/higher-vacations-fr';
import { higherGuard } from '../access';
import { VP } from './access';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], titleKey: HigherVacationsKey, withProps?: boolean];

export const VACATION_SCREENS: Screen[] = [
  ['vacations', 'higher-vacations', () => import('./StatementsView.vue'), VP.read, 'hv.nav.statements'],
  ['vacations/etats/:id', 'higher-vacation-statement', () => import('./StatementView.vue'), [...VP.read, ...VP.readOwn], 'hv.nav.statements', true],
  ['mes-vacations', 'higher-my-vacations', () => import('./MyVacationsView.vue'), VP.readOwn, 'hv.nav.mine'],
];

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherVacationsRoutes: RouteRecordRaw[] = VACATION_SCREENS.map(([path, name, view, perms, titleKey, withProps]): RouteRecordRaw => ({
  path: `/superieur/${path}`,
  name,
  component: view,
  props: withProps === true,
  beforeEnter: higherGuard,
  meta: { ...higherOnly, perms: [...perms], titleKey: titleKey as MessageKey },
}));
