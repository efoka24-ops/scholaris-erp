import type { RouteRecordRaw } from 'vue-router';

/** Écrans de la connexion simple (C-001) : changement de mot de passe (forcé après un mot de passe provisoire) et confirmation par mot de passe. */
export const accountRoutes: RouteRecordRaw[] = [
  { path: '/changer-mot-de-passe', name: 'change-password', component: () => import('@/views/account/ChangePasswordView.vue'), meta: { bare: true, titleKey: 'acc.pw.title' } },
  { path: '/confirmation', name: 'reconfirm', component: () => import('@/views/account/PasswordConfirmView.vue'), meta: { bare: true, titleKey: 'acc.confirm.title' } },
];
