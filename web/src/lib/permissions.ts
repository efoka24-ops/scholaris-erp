// Permissions par rôle, miroir des tables role_permissions du serveur (database/migrations).
// Servent uniquement à adapter l'interface (menus, garde de routes) : le serveur refuse de toute façon (403) une action
// non autorisée. Si l'API fournit un jour `permissions` dans /me/contexts, cette liste prime et ce miroir disparaît.
const ALL_ADMIN = [
  'organisation.read', 'organisation.manage', 'settings.manage', 'identity.manage', 'identity.roles.assign',
  'people.read', 'people.manage', 'billing.read', 'billing.manage', 'billing.reversal.approve',
  'messaging.read', 'messaging.send', 'messaging.templates.manage',
  'documents.read', 'documents.issue', 'documents.revoke', 'documents.templates.manage',
  'scheduling.read', 'scheduling.manage', 'attendance.read', 'attendance.justify',
  'attendance.discipline.manage', 'attendance.discipline.approve',
  'assessment.read', 'assessment.validate', 'assessment.publish', 'assessment.correction.approve',
  'assessment.council.manage', 'assessment.deliberation.manage',
  'staff.read', 'staff.manage', 'vacations.manage', 'vacations.validate',
  'privacy.requests.read', 'privacy.requests.manage', 'reporting.read',
  'staff.leave.request', 'staff.leave.approve', 'reporting.manage',
  // Libre-service des applications (migration 202610089080) : ouvrir et payer un abonnement.
  'applications.manage',
];

export const ROLE_PERMISSIONS: Record<string, string[]> = {
  super_admin: [...ALL_ADMIN, 'assessment.enter', 'privacy.export', 'reporting.group.read', 'settings.platform'],
  direction_groupe: [
    'organisation.read', 'organisation.manage', 'settings.manage', 'identity.manage', 'identity.roles.assign',
    'people.read', 'billing.read', 'billing.reversal.approve', 'messaging.read', 'messaging.send',
    'messaging.templates.manage', 'documents.read', 'documents.issue', 'documents.revoke', 'documents.templates.manage',
    'scheduling.read', 'attendance.read', 'assessment.read', 'staff.read', 'privacy.requests.read',
    'reporting.read', 'reporting.group.read', 'applications.manage',
  ],
  directeur: [...ALL_ADMIN.filter((p) => p !== 'assessment.enter')],
  comptable: [
    'organisation.read', 'people.read', 'billing.read', 'billing.manage', 'billing.reversal.approve',
    'finance.read', 'finance.manage', 'messaging.read', 'messaging.send', 'documents.read', 'documents.issue',
  ],
  caissier: [
    'organisation.read', 'people.read', 'billing.read', 'billing.cash', 'billing.reversal.request',
    'finance.read', 'finance.collect', 'documents.read',
  ],
  enseignant: ['organisation.read', 'assessment.enter', 'grades.enter', 'attendance.take', 'staff.leave.request'],
  parent: ['self.read', 'documents.request'],
  apprenant: ['self.read', 'documents.request'],
};

export function permissionsForRoles(roles: string[]): Set<string> {
  const set = new Set<string>();
  for (const role of roles) for (const p of ROLE_PERMISSIONS[role] ?? []) set.add(p);
  return set;
}
