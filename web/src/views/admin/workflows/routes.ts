import type { RouteRecordRaw } from 'vue-router';

/** Circuits de validation (Administration > Circuits de validation). Le serveur reste juge de la permission. */
export const workflowRoutes: RouteRecordRaw[] = [
  {
    path: '/administration/circuits',
    name: 'admin-workflows',
    component: () => import('@/views/admin/workflows/WorkflowsView.vue'),
    meta: { perms: ['workflows.manage'], needsEstablishment: true, titleKey: 'wf.nav' },
  },
];
