// Routes de la candidature en ligne IPES : page publique (sans garde d'authentification, hors espace connecté), espace du candidat
// et gestion du lien partageable. Reliées par views/higher/routes.ts (…higherApplyRoutes) : le routeur partagé reste intact.
import type { RouteRecordRaw } from 'vue-router';
import { higherGuard, P } from '../access';

const higherOnly = { needsEstablishment: true, establishmentTypes: ['higher'] };

export const higherApplyRoutes: RouteRecordRaw[] = [
  {
    path: '/candidater/:slug',
    name: 'higher-apply-public',
    component: () => import('./ApplyPublicView.vue'),
    props: true,
    meta: { public: true, titleKey: 'nav.higher' },
  },
  {
    path: '/superieur/ma-candidature',
    name: 'higher-my-application',
    component: () => import('./MyApplicationView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: ['higher.application.submit'], titleKey: 'nav.higher' },
  },
  {
    path: '/superieur/lien-candidature',
    name: 'higher-apply-link',
    component: () => import('./ApplyLinkView.vue'),
    beforeEnter: higherGuard,
    meta: { ...higherOnly, perms: [...P.applicationManage], titleKey: 'nav.higher' },
  },
];
