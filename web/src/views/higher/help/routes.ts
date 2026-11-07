// Route « Aide » de l'application IPES : fiches rapides statiques. Reliée dans router/routes.ts (…higherHelpRoutes).
import type { RouteRecordRaw } from 'vue-router';
import { higherGuard } from '../access';

export const higherHelpRoutes: RouteRecordRaw[] = [
  {
    path: '/superieur/aide',
    name: 'higher-help',
    component: () => import('./HelpView.vue'),
    beforeEnter: higherGuard,
    meta: { needsEstablishment: true, establishmentTypes: ['higher'], titleKey: 'nav.higherHelp' },
  },
];
