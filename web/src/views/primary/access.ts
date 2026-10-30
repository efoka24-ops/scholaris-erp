// Permissions utilisées par les écrans de l'école primaire privée. Le serveur reste juge des droits : ces listes adaptent seulement
// menus et boutons. Elles sont toutes préfixées primary. (les écoles publiques utilisent public_schools.*, jamais ces permissions).
export const P = {
  referentialRead: ['primary.referential.read', 'primary.referential.manage', 'primary.settings.manage'],
  referentialWrite: ['primary.referential.manage', 'primary.settings.manage'],
  settingsManage: ['primary.settings.manage'],
  learnerRead: ['primary.learner.read', 'primary.enrol', 'primary.settings.manage'],
  enrol: ['primary.enrol'],
  documentsIssue: ['documents.issue', 'primary.enrol'],
  attendance: ['attendance.take', 'attendance.read'],
  competencyEnter: ['primary.competency.enter'],
  competencyRead: ['primary.competency.read', 'primary.competency.enter'],
  competencyValidate: ['primary.competency.validate'],
  conductEnter: ['primary.conduct.enter'],
  conductRead: ['primary.conduct.read', 'primary.conduct.enter'],
  journalWrite: ['primary.journal.write'],
  journalRead: ['primary.journal.read', 'primary.journal.write'],
  dashboardRead: ['primary.dashboard.read'],
  /** Contrôles avant clôture : mêmes permissions que le serveur (sur tout l'établissement). */
  closureRead: ['primary.settings.manage', 'primary.promotion.decide', 'primary.dashboard.read'],
  councilRead: ['primary.council.read', 'primary.council.preside', 'assessment.validate'],
  councilPrepare: ['primary.council.preside', 'assessment.validate'],
  councilPreside: ['primary.council.preside'],
  reportCardRead: ['primary.report_card.read', 'primary.report_card.sign', 'primary.council.preside'],
  reportCardSign: ['primary.report_card.sign'],
  remediationRead: ['primary.remediation.read', 'primary.remediation.write'],
  remediationWrite: ['primary.remediation.write'],
  examRead: ['primary.exam.read', 'primary.exam.manage'],
  examManage: ['primary.exam.manage'],
  tutelageExport: ['primary.tutelage.export'],
  family: ['self.read'],
  /** Accès à l'espace : au moins une permission de travail dans l'école primaire privée. */
  space: [
    'primary.council.read', 'primary.report_card.read', 'primary.remediation.read', 'primary.exam.read', 'primary.tutelage.export',
    'primary.referential.read', 'primary.referential.manage', 'primary.settings.manage', 'primary.learner.read', 'primary.enrol',
    'primary.competency.enter', 'primary.competency.read', 'primary.conduct.enter', 'primary.journal.write', 'primary.journal.read', 'primary.dashboard.read',
  ],
} as const;
