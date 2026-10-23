// Routes « Documents officiels » de l'IPES (DOC) : noms attendus par HigherHomeView et HigherFrame (higher-documents,
// higher-school-profile). À ajouter au routeur : …higherDocumentsRoutes.
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { higherGuard, P } from '../access';

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherDocumentsRoutes: RouteRecordRaw[] = [
  {
    path: '/superieur/documents',
    name: 'higher-documents',
    component: () => import('./DocumentsView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...P.documentRead], titleKey: 'hi.doc.title' as MessageKey },
  },
  {
    path: '/superieur/documents/profil',
    name: 'higher-school-profile',
    component: () => import('./SchoolProfileView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...P.documentRead], titleKey: 'hi.doc.profileTitle' as MessageKey },
  },
];
