// Écrans N-002 de la maternelle publique (/maternelle/*) : contribution exigible, porte et registre du jour (avec effectifs). Réservés aux
// établissements de type `nursery` ; le serveur reste juge des droits (403/404) et de l'application ouverte (423 si suspendue).
import type { RouteRecordRaw } from 'vue-router';

const school = { needsEstablishment: true, establishmentTypes: ['nursery'] };

export const publicNurseryRoutes: RouteRecordRaw[] = [
  {
    path: '/maternelle/contribution',
    name: 'pn-contribution',
    component: () => import('./ContributionView.vue'),
    meta: { ...school, perms: ['public_nursery.contribution.manage', 'public_schools.school.write', 'settings.manage'], titleKey: 'pn.contrib.title' },
  },
  {
    path: '/maternelle/registre',
    name: 'pn-register',
    component: () => import('./GateRegisterView.vue'),
    meta: { ...school, perms: ['public_nursery.register.read', 'public_nursery.pickup.handover', 'public_schools.attendance.read', 'settings.manage'], titleKey: 'pn.gate.title' },
  },
  {
    path: '/maternelle/securite-sortie',
    name: 'pn-pickup-auth',
    component: () => import('./PickupAuthView.vue'),
    meta: { ...school, perms: ['public_nursery.pickup.handover', 'public_nursery.register.read', 'settings.manage'], titleKey: 'pn.auth.title' },
  },
  {
    path: '/maternelle/porte-hors-ligne',
    name: 'pn-gate-offline',
    component: () => import('./GateOfflineView.vue'),
    meta: { ...school, perms: ['public_nursery.pickup.handover', 'public_nursery.arrival.record', 'settings.manage'], titleKey: 'pn.offline.title' },
  },
];
