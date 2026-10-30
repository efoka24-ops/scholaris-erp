// Routes « Examens nationaux » de l'application IPES (module EXN) : réservées aux établissements higher, gardées par higherGuard.
// Reliées dans router/routes.ts (…higherNationalExamsRoutes). Les clés hx.* sont fusionnées dans fr.ts / en.ts.
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
    meta: { ...higherOnly, perms: [...X.read], titleKey: 'nav.higherNationalExams' },
  },
  {
    path: '/superieur/examens-nationaux/:id',
    name: 'higher-national-exam',
    component: () => import('./SessionView.vue'),
    props: true,
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...X.read], titleKey: 'nav.higherNationalExams' },
  },
];
