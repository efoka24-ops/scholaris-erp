// Routes « Examens nationaux » de l'application IPES (module EXN) : réservées aux établissements higher, gardées par higherGuard.
// À relier dans router/routes.ts (…higherNationalExamsRoutes). titleKey reste 'nav.higher' tant que les clés hx.* ne sont pas
// fusionnées dans fr.ts / en.ts (MessageKey).
import type { RouteRecordRaw } from 'vue-router';
import { higherGuard } from '../access';
import { X } from './perms';

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherNationalExamsRoutes: RouteRecordRaw[] = [
  {
    path: '/superieur/examens-nationaux',
    name: 'higher-national-exams',
    component: () => import('./SessionsView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...X.read], titleKey: 'nav.higher' },
  },
];
