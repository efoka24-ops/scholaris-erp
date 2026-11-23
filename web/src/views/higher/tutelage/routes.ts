// Routes « Tutelle » de l'application IPES (module TUT) : conventions, transmissions, dossiers de diplôme, accès de l'université (R22).
// Réservées aux établissements higher, gardées par higherGuard. Reliées dans router/routes.ts (…higherTutelageRoutes).
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { higherGuard } from '../access';
import { T } from '../national-exams/perms';

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };
const titleKey: MessageKey = 'nav.higherTutelage';

export const higherTutelageRoutes: RouteRecordRaw[] = [
  { path: '/superieur/tutelle', name: 'higher-tutelage', component: () => import('./ConventionsView.vue'), perms: [...T.internal] },
  { path: '/superieur/tutelle/transmissions', name: 'higher-tutelage-transmissions', component: () => import('./TransmissionsView.vue'), perms: [...T.internal] },
  { path: '/superieur/tutelle/dossiers-diplome', name: 'higher-tutelage-files', component: () => import('./DiplomaFilesView.vue'), perms: [...T.internal] },
  { path: '/superieur/tutelle/acces-universite', name: 'higher-tutelage-portal', component: () => import('./PortalView.vue'), perms: [...T.internal, ...T.external] },
].map(({ perms, ...route }): RouteRecordRaw => ({ ...route, beforeEnter: higherGuard, meta: { ...higherOnly, perms, titleKey } }));
