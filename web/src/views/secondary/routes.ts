// Routes de l'application « Enseignement secondaire » (/secondaire/*), réservées aux établissements de type `secondary`. Les écrans des
// autres lots s'ajoutent ici (édition ciblée) et apparaissent au menu par menu.ts (module actif + permission). Le serveur reste juge.
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { S, SPACE } from './access';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], titleKey: MessageKey];

const screens: Screen[] = [
  ['profil', 'sec-profile', () => import('./setup/ProfileView.vue'), S.profileRead, 'sec.profile.title'],
  ['documents', 'sec-documents', () => import('./documents/DocumentsView.vue'), S.documentsRead, 'sec.docs.title'],
  ['frais', 'sec-fees', () => import('./fees/FeesView.vue'), S.feesRead, 'secf.title'],
  ['carte-scolaire', 'sec-cartescolaire', () => import('./cartescolaire/CarteScolaireView.vue'), S.carteScolaire, 'seccs.title'],
  ['mon-espace', 'sec-student', () => import('./portals/StudentPortalView.vue'), S.self, 'sec.portal.studentTitle'],
  ['famille', 'sec-family', () => import('./portals/FamilyPortalView.vue'), S.family, 'sec.portal.familyTitle'],
];

const schoolOnly = { needsEstablishment: true, establishmentTypes: ['secondary'] };

export const secondaryRoutes: RouteRecordRaw[] = [
  {
    path: '/secondaire',
    name: 'sec-home',
    component: () => import('./HomeView.vue'),
    meta: { ...schoolOnly, perms: [...SPACE], titleKey: 'sec.home.title', nav: { labelKey: 'nav.secondary', order: 8 } },
  },
  ...screens.map(([path, name, view, perms, titleKey]): RouteRecordRaw => ({
    path: `/secondaire/${path}`,
    name,
    component: view,
    meta: { ...schoolOnly, perms: [...perms], titleKey },
  })),
];
