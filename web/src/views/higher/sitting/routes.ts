// Route de la séance de délibération d'un jury (moteur du socle), reliée depuis la fiche du jury. À ajouter au routeur :
// …higherSittingRoutes. Titre hx.sit.title : clé de higher-extra-fr.ts (à fusionner dans fr.ts).
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { higherGuard } from '../access';
import { DP } from '../deliberation/access';

export const higherSittingRoutes: RouteRecordRaw[] = [
  {
    path: '/superieur/deliberation/jurys/:id/seance',
    name: 'higher-del-sitting',
    component: () => import('./SittingView.vue'),
    props: true,
    beforeEnter: higherGuard,
    meta: { needsEstablishment: true, establishmentTypes: ['higher'], perms: [...DP.read], titleKey: 'hx.sit.title' as MessageKey },
  },
];
