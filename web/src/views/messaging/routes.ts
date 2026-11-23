import type { RouteRecordRaw } from 'vue-router';
import { CHAT_ROLES, MODERATOR_ROLES } from '@/api/messaging';

// Messagerie interne (C-113). Le miroir des permissions du front ne porte pas messaging.chat ni messaging.moderate :
// les écrans sont réservés aux rôles qui les détiennent côté serveur ; le serveur reste seul juge (403).
export const messagingRoutes: RouteRecordRaw[] = [
  {
    path: '/messagerie',
    name: 'inbox',
    component: () => import('./InboxView.vue'),
    meta: { roles: CHAT_ROLES, needsEstablishment: true, titleKey: 'msg.inbox.title', nav: { labelKey: 'nav.inbox', order: 71 } },
  },
  {
    path: '/messagerie/nouvelle',
    name: 'inbox-new',
    component: () => import('./NewConversationView.vue'),
    meta: { roles: CHAT_ROLES, needsEstablishment: true, titleKey: 'msg.new.title' },
  },
  {
    path: '/messagerie/moderation',
    name: 'inbox-moderation',
    component: () => import('./ModerationView.vue'),
    meta: { roles: MODERATOR_ROLES, needsEstablishment: true, titleKey: 'msg.mod.title' },
  },
  {
    path: '/messagerie/:id',
    name: 'inbox-thread',
    component: () => import('./ThreadView.vue'),
    props: true,
    meta: { roles: CHAT_ROLES, needsEstablishment: true, titleKey: 'msg.inbox.title' },
  },
  {
    path: '/platform/support',
    name: 'platform-support',
    component: () => import('@/views/platform/PlatformSupportView.vue'),
    meta: { platform: true, perms: ['platform.support.manage'], titleKey: 'msg.sup.title', nav: { labelKey: 'nav.platform.support', order: 9 } },
  },
  {
    path: '/platform/support/:id',
    name: 'platform-support-thread',
    component: () => import('@/views/platform/PlatformSupportThreadView.vue'),
    props: true,
    meta: { platform: true, perms: ['platform.support.manage'], titleKey: 'msg.sup.title' },
  },
];
