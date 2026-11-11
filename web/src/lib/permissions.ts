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

// Secondaire (migrations 202610281601_roles_secondary.sql, T808, et 202610291001_roles_secondary_billing.sql, droits Billing) : R09 est
// le rôle commun « enseignant », les autres rôles sont sec_*.
const SECONDARY_TEACHER = [
  'secondary.timetable.read', 'secondary.syllabus.read', 'secondary.lesson.log', 'secondary.attendance.read', 'secondary.attendance.take',
  'secondary.grades.read', 'secondary.grades.enter', 'secondary.reportcard.read', 'secondary.appraisal.subject', 'secondary.workshops.read',
  'secondary.workshops.session', 'secondary.dashboard.read',
];
const sec = (...names: string[]): string[] => names.map((n) => (n.startsWith('@') ? n.slice(1) : `secondary.${n}`));
const SECONDARY_ROLE_PERMISSIONS: Record<string, string[]> = {
  sec_promoteur: sec('dashboard.read', 'finance.read', 'fees.validate', 'profile.read', 'catalogue.read', '@billing.read'),
  sec_reseau: sec('network.read'),
  sec_chef_etablissement: sec(
    'settings.read', 'settings.manage', 'enrol.read', 'enrol.validate', 'timetable.read', 'timetable.validate', 'syllabus.read', 'attendance.read',
    'discipline.validate', 'grades.read', 'reportcard.read', 'reportcard.validate', 'council.preside', 'exams.read', 'exams.validate', 'fees.read',
    'workshops.read', 'dashboard.read', 'discipline.council.preside', 'document.sign', 'profile.read', 'catalogue.read', 'fees.validate', '@billing.read',
    '@assessment.read', '@assessment.council.manage', '@organisation.read',
  ),
  sec_censeur: sec(
    'settings.read', 'settings.write', 'enrol.read', 'timetable.read', 'timetable.manage', 'syllabus.read', 'syllabus.visa', 'attendance.read',
    'grades.read', 'grades.validate', 'grades.lock', 'reportcard.read', 'council.prepare', 'exams.read', 'exams.manage', 'workshops.read',
    'dashboard.read', 'profile.read', 'catalogue.read', '@assessment.read', '@assessment.validate', '@assessment.council.manage', '@vacations.validate', '@organisation.read',
  ),
  sec_surveillant_general: sec(
    'enrol.read', 'timetable.read', 'attendance.read', 'attendance.manage', 'discipline.manage', 'reportcard.read', 'conduct.enter', 'dashboard.read', 'profile.read',
    '@attendance.read', '@attendance.justify', '@organisation.read',
  ),
  sec_chef_travaux: sec('workshops.manage', 'stock.manage', 'safety.manage', 'internships.manage'),
  sec_animateur_pedagogique: sec('syllabus.manage', 'commontests.manage', 'catalogue.read', '@organisation.read'),
  sec_professeur_principal: sec(
    'enrol.read', 'timetable.read', 'syllabus.read', 'attendance.read', 'grades.read', 'reportcard.read', 'appraisal.general', 'class.synthesis',
    'exams.read', 'dashboard.read', '@assessment.read', '@organisation.read',
  ),
  sec_conseiller_orientation: sec('orientation.manage', 'catalogue.read', '@organisation.read'),
  sec_intendant: sec(
    'settings.read', 'enrol.read', 'exams.read', 'fees.read', 'billing.manage', 'fees.catalog', 'workshops.costs.read', 'finance.read', 'profile.read', 'catalogue.read',
    '@billing.read', '@billing.manage', '@billing.reversal.approve', '@organisation.read',
  ),
  sec_caissier: sec('billing.cash', '@billing.read', '@billing.cash', '@billing.reversal.request'),
  sec_scolarite: sec('enrol.manage', 'documents.issue', 'exams.lists', 'profile.read', 'catalogue.read', '@organisation.read'),
  sec_infirmier: sec('infirmary.manage'),
  sec_responsable_services: sec('services.manage'),
  sec_bibliothecaire: sec('library.manage'),
  sec_eleve: sec('self.read'),
  sec_delegue_classe: sec('attendance.countersign'),
  sec_parent: sec('family.read', 'family.pay'),
  sec_bureau_apee: sec('apee.read'),
  sec_inspecteur: sec('inspection.read'),
  sec_delegation: sec('territory.read'),
  sec_brigade_controle: sec('control.read'),
  sec_admin_fonctionnel: sec(
    'settings.manage', 'profile.manage', 'documents.template.manage', 'profile.read', 'catalogue.read', '@identity.manage', '@identity.roles.assign', '@organisation.read',
  ),
};

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
    ...SECONDARY_TEACHER,
  ],
  parent: ['self.read', 'documents.request', 'public_schools.apee.read_receipts'],
  apprenant: ['self.read', 'documents.request'],
  // Écoles publiques (migration 202610152351_roles_public_schools.sql) : R01-R10 et R15-R21 ; R13 est le rôle « parent » ci-dessus.
  // Maternelle publique (migration 202610161240_roles_public_nursery.sql) : R11 et R12 ; R14 n'est pas un compte.
  pub_cabinet: ['public_schools.aggregates.read'],
  // Migration 202611110830 (FIX-PUB) : organisation.read pour ouvrir une session d'examen (années scolaires).
  pub_exam_directorate: ['public_schools.aggregates.read', 'public_primary.exams.consolidate', 'public_primary.exams.import', 'organisation.read'],
  pub_planning: ['public_schools.aggregates.read', 'public_schools.census.validate'],
  pub_inspection_general: ['public_schools.aggregates.read', 'public_schools.inspection.read', 'documents.templates.manage', 'public_primary.referential.manage', 'public_schools.inspection.manage'],
  pub_regional_delegate: [
    'public_schools.school.read', 'public_schools.aggregates.read', 'public_schools.census.validate', 'public_schools.messages.announce_territory', 'public_primary.exams.read',
  ],
  pub_departmental_delegate: [
    'public_schools.school.read', 'public_schools.pupils.read', 'public_schools.pupils.named.read', 'public_schools.staff.read',
    'public_schools.inspection.read', 'public_schools.aggregates.read', 'public_schools.census.validate', 'public_schools.messages.send',
    'public_schools.messages.announce_territory', 'public_primary.exams.validate', 'public_primary.exams.import',
    // Migration 202610281401 (lot P3) : consolidation et signature des attestations, PDF émis.
    'public_primary.exams.consolidate', 'public_primary.exams.attest', 'documents.read',
  ],
  pub_district_inspector: [
    'public_schools.school.read', 'public_schools.school.validate', 'public_schools.pupils.read', 'public_schools.pupils.named.read',
    'public_schools.teacher_presence.read', 'public_schools.teacher_presence.validate', 'public_schools.staff.read', 'public_schools.messages.send',
    'public_schools.messages.announce_territory', 'public_schools.apee.read', 'public_schools.census.validate', 'public_schools.inspection.read',
    'public_schools.inspection.write', 'public_schools.aggregates.read', 'public_primary.promotion.opinion', 'public_primary.exams.validate',
    'organisation.read', 'sync.batches.submit', 'documents.read',
  ],
  pub_pedagogical_advisor: ['public_schools.school.read', 'public_schools.inspection.read', 'public_schools.inspection.write', 'documents.read'],
  pub_school_director: [
    'public_schools.school.read', 'public_schools.school.write', 'public_schools.pupils.read', 'public_schools.pupils.write',
    'public_schools.attendance.read', 'public_schools.attendance.write', 'public_schools.teacher_presence.read', 'public_schools.teacher_presence.write',
    'public_schools.staff.read', 'public_schools.staff.write', 'public_schools.messages.send', 'public_schools.apee.read', 'public_schools.census.write',
    'public_schools.inspection.read', 'public_primary.evaluation.validate', 'public_primary.evaluation.publish', 'public_primary.evaluation.rectify',
    'public_primary.promotion.validate', 'public_primary.exams.write', 'organisation.read', 'sync.batches.submit', 'sync.devices.manage',
    // Migrations 202610231301 (PDF émis) et 202610231380 (section maternelle d'un groupe scolaire).
    'documents.read', 'public_nursery.contribution.manage', 'public_nursery.register.read',
    // Migration 202610281401 (lot P3) : consultation de la cantine et des campagnes de santé.
    'public_schools.canteen.read',
    // Migration 202611110830 (FIX-PUB) : référentiel d'exemple et unités de l'école, si le paramètre school_may_manage le permet.
    'public_primary.referential.school_manage',
  ],
  pub_class_teacher: [
    'public_schools.school.read', 'public_schools.pupils.read', 'public_schools.attendance.read', 'public_schools.attendance.write',
    'public_schools.teacher_presence.read', 'public_schools.messages.send', 'public_schools.inspection.read', 'public_primary.evaluation.write',
    'public_primary.promotion.write', 'organisation.read', 'sync.batches.submit', 'public_primary.exams.read',
  ],
  pub_apee_board: [
    'public_schools.school.read', 'public_schools.apee.read', 'public_schools.apee.write', 'public_schools.apee.read_receipts',
    'public_schools.apee.non_payers.read', 'documents.read',
  ],
  pub_school_council: ['public_schools.school.read', 'public_schools.apee.read'],
  pub_canteen_manager: ['public_schools.school.read', 'public_schools.canteen.write', 'public_schools.canteen.read'],
  pub_school_health_agent: ['public_schools.school.read', 'public_schools.canteen.write', 'public_schools.canteen.read'],
  pub_technical_partner: ['public_schools.aggregates.read'],
  pub_inspection_clerk: ['public_schools.school.read', 'public_schools.pupils.read', 'public_schools.pupils.write', 'public_schools.census.write'],
  pub_functional_admin: [
    'organisation.read', 'organisation.territories.manage', 'identity.manage', 'identity.roles.assign', 'settings.manage',
    'documents.templates.manage', 'messaging.templates.manage', 'sync.devices.manage', 'public_primary.referential.manage',
    'public_schools.inspection.manage',
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
    'organisation.read',
  ],
  primary_directeur: [
    'primary.settings.manage', 'primary.council.preside', 'primary.report_card.sign', 'assessment.correction.approve', 'primary.promotion.decide', 'primary.dashboard.read',
    'primary.referential.read', 'primary.learner.read', 'primary.competency.read', 'primary.competency.validate', 'primary.conduct.read', 'primary.journal.read',
    'primary.council.read', 'primary.report_card.read', 'primary.remediation.read', 'primary.remediation.write', 'primary.exam.read', 'primary.exam.manage',
    'primary.tutelage.export', 'primary.services.read', 'primary.pickup.read', 'primary.pickup.handover', 'organisation.read',
  ],
  primary_adjoint: [
    'primary.referential.manage', 'assessment.validate', 'scheduling.manage', 'primary.referential.read', 'primary.learner.read', 'primary.competency.read',
    'primary.competency.validate', 'primary.conduct.read', 'primary.journal.read',
    'primary.council.read', 'primary.report_card.read', 'primary.remediation.read', 'primary.remediation.write', 'primary.exam.read', 'organisation.read',
  ],
  primary_secretaire: [
    'people.manage', 'primary.enrol', 'documents.issue', 'primary.exam.manage', 'primary.referential.read', 'primary.learner.read',
    'primary.report_card.read', 'primary.exam.read', 'primary.tutelage.export', 'primary.services.read', 'organisation.read',
  ],
  primary_surveillant: ['attendance.sanction.manage', 'primary.pickup.handover', 'primary.pickup.read', 'organisation.read'],
  ...SECONDARY_ROLE_PERMISSIONS,
};
// organisation.read des rôles primary_*, nursery_* et sec_* : migration 202611080101_roles_organisation_read.sql (C-115, défaut C110-PR1).

// Messagerie interne et support SCHOLARIS (migrations 202610090901 et 202611060201, C-113).
const MESSAGING_EXTRA: Record<string, string[]> = {
  super_admin: ['messaging.chat', 'messaging.moderate', 'messaging.support'],
  directeur: ['messaging.chat', 'messaging.moderate', 'messaging.support'],
  enseignant: ['messaging.chat'],
  parent: ['messaging.chat'],
};
for (const [role, extra] of Object.entries(MESSAGING_EXTRA)) ROLE_PERMISSIONS[role] = [...new Set([...(ROLE_PERMISSIONS[role] ?? []), ...extra])];

export function permissionsForRoles(roles: string[]): Set<string> {
  const set = new Set<string>();
  for (const role of roles) for (const p of ROLE_PERMISSIONS[role] ?? []) set.add(p);
  return set;
}

// Rôles de la plateforme (client technique `platform`, migration 202611060101_platform_roles.sql, C-112). Miroir de
// role_permissions : GET /platform/me rend les permissions effectives, qui priment ; ce miroir sert de repli.
const PLATFORM_SUPPORT = [
  'platform.access', 'platform.dashboard.read', 'platform.health.read', 'platform.clients.read', 'platform.catalog.read',
  'platform.requests.read', 'platform.users.read', 'platform.users.logout', 'platform.users.access_code', 'platform.audit.read',
  'platform.messages.read', 'platform.messages.retry', 'platform.messages.send',
  // Boîte du support SCHOLARIS (migration 202611060202, C-113).
  'platform.support.manage',
];
const PLATFORM_FINANCE = [
  'platform.access', 'platform.dashboard.read', 'platform.kpi.finance', 'platform.clients.read', 'platform.catalog.read',
  'platform.payments.read', 'platform.payments.manage', 'platform.pricing.manage', 'platform.regularisation.manage',
];
const PLATFORM_AUDITOR = [
  'platform.access', 'platform.dashboard.read', 'platform.kpi.finance', 'platform.health.read', 'platform.clients.read',
  'platform.catalog.read', 'platform.requests.read', 'platform.payments.read', 'platform.settings.read', 'platform.users.read',
  'platform.admins.read', 'platform.audit.read', 'platform.audit.export', 'platform.messages.read',
];
const PLATFORM_ADMIN = [
  ...new Set([
    ...PLATFORM_SUPPORT, ...PLATFORM_FINANCE, ...PLATFORM_AUDITOR,
    'platform.clients.manage', 'platform.catalog.manage', 'platform.requests.decide', 'platform.applications.manage',
    'platform.admins.manage', 'platform.messages.broadcast', 'platform.settings.manage', 'platform.support.open',
  ]),
];

export const PLATFORM_ROLES = ['platform_admin', 'platform_support', 'platform_finance', 'platform_auditor'] as const;
export type PlatformRole = (typeof PLATFORM_ROLES)[number];

export const PLATFORM_ROLE_PERMISSIONS: Record<PlatformRole, string[]> = {
  platform_admin: PLATFORM_ADMIN,
  platform_support: PLATFORM_SUPPORT,
  platform_finance: PLATFORM_FINANCE,
  platform_auditor: PLATFORM_AUDITOR,
};

export function platformPermissionsFor(role: string | null | undefined): Set<string> {
  return new Set(role && role in PLATFORM_ROLE_PERMISSIONS ? PLATFORM_ROLE_PERMISSIONS[role as PlatformRole] : []);
}
