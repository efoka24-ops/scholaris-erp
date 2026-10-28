// Permissions par rôle, miroir des tables role_permissions du serveur (database/migrations).
// Servent uniquement à adapter l'interface (menus, garde de routes) : le serveur refuse de toute façon (403) une action
// non autorisée. Si l'API fournit un jour `permissions` dans /me/contexts, cette liste prime et ce miroir disparaît.
import { NURSERY_ROLE_PERMISSIONS } from '@/views/nursery-private/rolePermissions';

const ALL_ADMIN = [
  'organisation.read', 'organisation.manage', 'settings.manage', 'identity.manage', 'identity.roles.assign',
  'people.read', 'people.manage', 'billing.read', 'billing.manage', 'billing.reversal.approve',
  'billing.reversal.approve.direction',
  'messaging.read', 'messaging.send', 'messaging.templates.manage',
  'documents.read', 'documents.issue', 'documents.revoke', 'documents.rectify', 'documents.templates.manage',
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
    'messaging.templates.manage', 'documents.read', 'documents.issue', 'documents.revoke', 'documents.rectify', 'documents.templates.manage',
    'billing.reversal.approve.direction', 'scheduling.read', 'attendance.read', 'assessment.read', 'staff.read', 'privacy.requests.read',
    'reporting.read', 'reporting.group.read', 'applications.manage',
  ],
  directeur: [...ALL_ADMIN.filter((p) => p !== 'assessment.enter')],
  comptable: [
    'organisation.read', 'people.read', 'billing.read', 'billing.manage', 'billing.reversal.approve',
    'finance.read', 'finance.manage', 'messaging.read', 'messaging.send', 'documents.read', 'documents.issue',
    'primary.services.read', 'primary.services.manage',
  ],
  caissier: [
    'organisation.read', 'people.read', 'billing.read', 'billing.cash', 'billing.reversal.request',
    'finance.read', 'finance.collect', 'documents.read',
  ],
  enseignant: [
    'organisation.read', 'assessment.enter', 'grades.enter', 'attendance.take', 'staff.leave.request',
    'primary.referential.read', 'primary.learner.read', 'primary.competency.enter', 'primary.competency.read', 'primary.conduct.enter', 'primary.conduct.read',
    'primary.journal.write', 'primary.journal.read',
    'primary.council.read', 'primary.report_card.read', 'primary.remediation.read', 'primary.remediation.write',
  ],
  parent: ['self.read', 'documents.request', 'public_schools.apee.read_receipts'],
  apprenant: ['self.read', 'documents.request'],
  // Écoles publiques (migration 202610152351_roles_public_schools.sql) : R01-R10 et R15-R21 ; R13 est le rôle « parent » ci-dessus.
  // Maternelle publique (migration 202610161240_roles_public_nursery.sql) : R11 et R12 ; R14 n'est pas un compte.
  pub_cabinet: ['public_schools.aggregates.read'],
  pub_exam_directorate: ['public_schools.aggregates.read', 'public_primary.exams.consolidate', 'public_primary.exams.import'],
  pub_planning: ['public_schools.aggregates.read', 'public_schools.census.validate'],
  pub_inspection_general: ['public_schools.aggregates.read', 'public_schools.inspection.read', 'documents.templates.manage'],
  pub_regional_delegate: [
    'public_schools.school.read', 'public_schools.aggregates.read', 'public_schools.census.validate', 'public_schools.messages.announce_territory',
  ],
  pub_departmental_delegate: [
    'public_schools.school.read', 'public_schools.pupils.read', 'public_schools.pupils.named.read', 'public_schools.staff.read',
    'public_schools.inspection.read', 'public_schools.aggregates.read', 'public_schools.census.validate', 'public_schools.messages.send',
    'public_schools.messages.announce_territory', 'public_primary.exams.validate', 'public_primary.exams.import',
  ],
  pub_district_inspector: [
    'public_schools.school.read', 'public_schools.school.validate', 'public_schools.pupils.read', 'public_schools.pupils.named.read',
    'public_schools.teacher_presence.read', 'public_schools.teacher_presence.validate', 'public_schools.staff.read', 'public_schools.messages.send',
    'public_schools.messages.announce_territory', 'public_schools.apee.read', 'public_schools.census.validate', 'public_schools.inspection.read',
    'public_schools.inspection.write', 'public_schools.aggregates.read', 'public_primary.promotion.opinion', 'public_primary.exams.validate',
    'organisation.read', 'sync.batches.submit',
  ],
  pub_pedagogical_advisor: ['public_schools.school.read', 'public_schools.inspection.read', 'public_schools.inspection.write'],
  pub_school_director: [
    'public_schools.school.read', 'public_schools.school.write', 'public_schools.pupils.read', 'public_schools.pupils.write',
    'public_schools.attendance.read', 'public_schools.attendance.write', 'public_schools.teacher_presence.read', 'public_schools.teacher_presence.write',
    'public_schools.staff.read', 'public_schools.staff.write', 'public_schools.messages.send', 'public_schools.apee.read', 'public_schools.census.write',
    'public_schools.inspection.read', 'public_primary.evaluation.validate', 'public_primary.evaluation.publish', 'public_primary.evaluation.rectify',
    'public_primary.promotion.validate', 'public_primary.exams.write', 'organisation.read', 'sync.batches.submit', 'sync.devices.manage',
  ],
  pub_class_teacher: [
    'public_schools.school.read', 'public_schools.pupils.read', 'public_schools.attendance.read', 'public_schools.attendance.write',
    'public_schools.teacher_presence.read', 'public_schools.messages.send', 'public_schools.inspection.read', 'public_primary.evaluation.write',
    'public_primary.promotion.write', 'organisation.read', 'sync.batches.submit',
  ],
  pub_apee_board: [
    'public_schools.school.read', 'public_schools.apee.read', 'public_schools.apee.write', 'public_schools.apee.read_receipts',
    'public_schools.apee.non_payers.read',
  ],
  pub_school_council: ['public_schools.school.read', 'public_schools.apee.read'],
  pub_canteen_manager: ['public_schools.school.read', 'public_schools.canteen.write'],
  pub_school_health_agent: ['public_schools.school.read', 'public_schools.canteen.write'],
  pub_technical_partner: ['public_schools.aggregates.read'],
  pub_inspection_clerk: ['public_schools.school.read', 'public_schools.pupils.read', 'public_schools.pupils.write', 'public_schools.census.write'],
  pub_functional_admin: [
    'organisation.read', 'organisation.territories.manage', 'identity.manage', 'identity.roles.assign', 'settings.manage',
    'documents.templates.manage', 'messaging.templates.manage', 'sync.devices.manage',
  ],
  pn_educator: [
    'public_nursery.pickup.handover', 'public_nursery.dev.observe', 'public_nursery.lifebook.write',
    'public_nursery.need.report', 'public_nursery.transition.opinion',
  ],
  pn_assistant: ['public_nursery.pickup.handover', 'public_nursery.arrival.record'],
  ...NURSERY_ROLE_PERMISSIONS,
  primary_promoteur: [
    'primary.dashboard.read', 'reporting.read', 'primary.referential.read', 'primary.learner.read', 'primary.competency.read', 'primary.conduct.read', 'primary.journal.read',
    'primary.council.read', 'primary.report_card.read', 'primary.remediation.read', 'primary.exam.read', 'primary.tutelage.export', 'primary.services.read',
  ],
  primary_directeur: [
    'primary.settings.manage', 'primary.council.preside', 'primary.report_card.sign', 'assessment.correction.approve', 'primary.promotion.decide', 'primary.dashboard.read',
    'primary.referential.read', 'primary.learner.read', 'primary.competency.read', 'primary.competency.validate', 'primary.conduct.read', 'primary.journal.read',
    'primary.council.read', 'primary.report_card.read', 'primary.remediation.read', 'primary.remediation.write', 'primary.exam.read', 'primary.exam.manage',
    'primary.tutelage.export', 'primary.services.read', 'primary.pickup.read', 'primary.pickup.handover',
  ],
  primary_adjoint: [
    'primary.referential.manage', 'assessment.validate', 'scheduling.manage', 'primary.referential.read', 'primary.learner.read', 'primary.competency.read',
    'primary.competency.validate', 'primary.conduct.read', 'primary.journal.read',
    'primary.council.read', 'primary.report_card.read', 'primary.remediation.read', 'primary.remediation.write', 'primary.exam.read',
  ],
  primary_secretaire: [
    'people.manage', 'primary.enrol', 'documents.issue', 'primary.exam.manage', 'primary.referential.read', 'primary.learner.read',
    'primary.report_card.read', 'primary.exam.read', 'primary.tutelage.export', 'primary.services.read',
  ],
  primary_surveillant: ['attendance.sanction.manage', 'primary.pickup.handover', 'primary.pickup.read'],
};

export function permissionsForRoles(roles: string[]): Set<string> {
  const set = new Set<string>();
  for (const role of roles) for (const p of ROLE_PERMISSIONS[role] ?? []) set.add(p);
  return set;
}
