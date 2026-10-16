// Routes des portails étudiant et garant de l'IPES (POR) : noms attendus par HigherHomeView (higher-portal-student,
// higher-portal-guarantor). Périmètre « soi » (self.read) ; à ajouter au routeur : …higherPortalRoutes.
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { higherGuard } from '../access';

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherPortalRoutes: RouteRecordRaw[] = [
  {
    path: '/superieur/mon-espace',
    name: 'higher-portal-student',
    component: () => import('./StudentPortalView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, roles: ['apprenant'], perms: ['self.read'], titleKey: 'hi.por.studentTitle' as MessageKey },
  },
  {
    path: '/superieur/espace-garant',
    name: 'higher-portal-guarantor',
    component: () => import('./GuarantorPortalView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, roles: ['parent'], perms: ['self.read'], titleKey: 'hi.por.guarantorTitle' as MessageKey },
  },
];
