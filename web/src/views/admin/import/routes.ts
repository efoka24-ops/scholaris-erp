import type { RouteRecordRaw } from 'vue-router';

/** Import en masse des acteurs d'un établissement (Administration > Importer des personnes). Le serveur reste juge de la permission. */
export const importRoutes: RouteRecordRaw[] = [
  {
    path: '/administration/import',
    name: 'admin-import',
    component: () => import('@/views/admin/import/ImportView.vue'),
    meta: { perms: ['imports.people.manage'], needsEstablishment: true, titleKey: 'imports.nav' },
  },
];
