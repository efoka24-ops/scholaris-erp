// Routes de la Maternelle privée (/maternelle-privee/*). Réservées aux établissements de type `nursery` ET aux permissions nursery.* : les
// maternelles publiques ont leurs propres écrans, le menu d'une école privée ne renvoie jamais vers un écran public. Le serveur reste
// juge des droits (403/404) ; les permissions adaptent seulement le menu et l'accès aux écrans.
import type { RouteRecordRaw } from 'vue-router';
import type { MessageKey } from '@/i18n';
import { P } from './access';

type Screen = [path: string, name: string, view: () => Promise<unknown>, perms: readonly string[], titleKey: MessageKey];

const screens: Screen[] = [
  ['sortie', 'nur-pickup', () => import('./PickupView.vue'), P.handover, 'nur.pickup.title'],
  ['appel', 'nur-call', () => import('./CallView.vue'), P.call, 'nur.call.title'],
  ['registre', 'nur-register', () => import('./RegisterView.vue'), P.register, 'nur.register.title'],
  ['observation', 'nur-observe', () => import('./ObserveView.vue'), P.observe, 'nur.observe.title'],
  ['carnets', 'nur-books', () => import('./BooksView.vue'), P.devRead, 'nur.books.title'],
  ['cahier-de-vie', 'nur-lifebook', () => import('./LifeBookView.vue'), P.lifebook, 'nur.life.title'],
  ['besoins', 'nur-needs', () => import('./NeedsView.vue'), P.needs, 'nur.needs.title'],
  ['autorises', 'nur-authorized', () => import('./AuthorizedView.vue'), P.authorized, 'nur.auth.title'],
  ['inscription', 'nur-enrol', () => import('./EnrolView.vue'), P.enrol, 'nur.enrol.title'],
  ['frais', 'nur-fees', () => import('./FeesView.vue'), P.fees, 'nur.fees.title'],
  ['messages', 'nur-messages', () => import('./MessagesView.vue'), P.messages, 'nur.msg.title'],
  ['sections', 'nur-sections', () => import('./SectionsView.vue'), P.sections, 'nur.section.title'],
  ['referentiel', 'nur-frames', () => import('./FramesView.vue'), P.frames, 'nur.frames.title'],
];

const schoolOnly = { needsEstablishment: true, establishmentTypes: ['nursery'] };

export const nurseryPrivateRoutes: RouteRecordRaw[] = [
  {
    path: '/maternelle-privee',
    name: 'nur-home',
    component: () => import('./HomeView.vue'),
    meta: { ...schoolOnly, perms: [...P.space], titleKey: 'nur.home.title', nav: { labelKey: 'nav.nurseryPrivate', order: 9 } },
  },
  ...screens.map(([path, name, view, perms, titleKey]): RouteRecordRaw => ({
    path: `/maternelle-privee/${path}`,
    name,
    component: view,
    meta: { ...schoolOnly, perms: [...perms], titleKey },
  })),
  // Espace des parents : pas de permission nursery.* (le parent est reconnu par le lien de garde côté serveur).
  {
    path: '/maternelle-privee/famille',
    name: 'nur-family',
    component: () => import('./FamilyView.vue'),
    meta: { ...schoolOnly, roles: ['parent'], titleKey: 'nur.family.title', nav: { labelKey: 'nav.nurseryFamily', order: 9 } },
  },
];
