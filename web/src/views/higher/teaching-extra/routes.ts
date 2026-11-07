// Routes « délégués de groupe » et « emploi du temps imprimable » de l'IPES. À ajouter au routeur : …higherTeachingExtraRoutes.
// Titres hx.* : clés de higher-extra-fr.ts (à fusionner dans fr.ts).
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { higherGuard } from '../access';
import { XP } from './access';

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherTeachingExtraRoutes: RouteRecordRaw[] = [
  {
    path: '/superieur/enseignement/delegues',
    name: 'higher-group-delegates',
    component: () => import('./DelegatesView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...XP.delegateRead], titleKey: 'hx.del.title' as MessageKey },
  },
  {
    path: '/superieur/enseignement/emploi-du-temps/impression',
    name: 'higher-timetable-print',
    component: () => import('./TimetablePrintView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...XP.timetableRead], titleKey: 'hx.print.title' as MessageKey },
  },
];
