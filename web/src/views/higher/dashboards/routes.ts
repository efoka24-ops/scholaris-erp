// Routes « Tableaux de bord » de l'application IPES (module PIL) : réservées aux établissements higher, gardées par higherGuard.
// À relier dans router/routes.ts (…higherDashboardsRoutes). Le serveur impose périmètre et permission de donnée de chaque tableau.
import type { RouteRecordRaw } from 'vue-router';
import { higherGuard } from '../access';

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };
const perms = ['higher.dashboard.read'];

export const higherDashboardsRoutes: RouteRecordRaw[] = [
  {
    path: '/superieur/tableaux-de-bord',
    name: 'higher-dashboards',
    component: () => import('./DashboardsIndexView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms, titleKey: 'nav.higher' },
  },
  {
    path: '/superieur/tableaux-de-bord/:kind',
    name: 'higher-dashboard',
    component: () => import('./DashboardView.vue'),
    props: true,
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms, titleKey: 'nav.higher' },
  },
];
