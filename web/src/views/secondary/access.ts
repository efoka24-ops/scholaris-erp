// Permissions des écrans du secondaire (miroir de la migration 202610281601_roles_secondary.sql). Le serveur reste juge des droits :
// ces listes adaptent seulement menus et boutons. L'élève et le parent lisent par leur propre périmètre (self.read, family.*).
export const S = {
  profileRead: ['secondary.profile.read', 'secondary.profile.manage', 'secondary.settings.manage', 'secondary.settings.read'],
  profileManage: ['secondary.profile.manage', 'secondary.settings.manage'],
  settingsRead: ['secondary.settings.read', 'secondary.settings.write', 'secondary.settings.manage'],
  enrolRead: ['secondary.enrol.read', 'secondary.enrol.manage', 'secondary.enrol.validate'],
  documentsRead: ['secondary.documents.issue', 'secondary.enrol.manage', 'secondary.enrol.read', 'secondary.billing.manage', 'secondary.settings.manage'],
  feesRead: ['secondary.fees.read', 'secondary.billing.manage', 'secondary.billing.cash', 'secondary.fees.catalog', 'secondary.finance.read', 'secondary.profile.manage'],
  carteScolaire: ['secondary.enrol.read', 'secondary.enrol.manage', 'secondary.billing.manage', 'secondary.fees.read', 'secondary.finance.read'],
  examsRead: ['secondary.exams.read', 'secondary.exams.manage', 'secondary.exams.lists', 'secondary.exams.validate'],
  orientation: ['secondary.orientation.manage', 'secondary.council.preside', 'secondary.self.read', 'secondary.family.read'],
  councils: ['secondary.council.prepare', 'secondary.council.preside'],
  reportCards: ['secondary.reportcard.read', 'secondary.reportcard.validate', 'secondary.council.prepare', 'secondary.council.preside'],
  workshops: ['secondary.workshops.read', 'secondary.workshops.manage', 'secondary.workshops.session'],
  internships: ['secondary.internships.manage'],
  /** Personnel : aussi l'administration du personnel du socle (staff.read, staff.manage : administrateur du client, FIX-SEC). */
  staff: ['secondary.settings.read', 'secondary.settings.manage', 'secondary.syllabus.visa', 'secondary.timetable.manage', 'secondary.billing.manage', 'secondary.finance.read', 'staff.read', 'staff.manage'],
  payroll: ['secondary.finance.read', 'secondary.billing.manage', 'secondary.settings.manage', 'staff.manage'],
  services: ['secondary.services.manage', 'secondary.library.manage', 'secondary.infirmary.manage', 'secondary.billing.manage', 'secondary.settings.manage', 'secondary.finance.read'],
  financeReports: ['secondary.fees.read', 'secondary.finance.read', 'secondary.billing.manage', 'secondary.fees.catalog', 'secondary.fees.validate', 'secondary.enrol.manage', 'secondary.settings.manage'],
  teacher: ['secondary.lesson.log', 'secondary.grades.enter', 'secondary.attendance.take'],
  /** Reprise de données (T977) : un droit de l'un des jeux (classes, élèves, soldes) ; le serveur juge chaque jeu. */
  migration: ['secondary.settings.write', 'secondary.settings.manage', 'secondary.enrol.manage', 'secondary.billing.manage'],
  dashboards: ['secondary.dashboard.read', 'secondary.finance.read', 'secondary.fees.read', 'secondary.billing.manage', 'secondary.network.read', 'secondary.grades.validate'],
  dashboardHead: ['secondary.dashboard.read'],
  /** Rôles affectés à tout l'établissement (chef, censeur, surveillant général, promoteur) : vue du chef proposée ; le serveur juge. */
  dashboardWhole: ['secondary.settings.manage', 'secondary.timetable.manage', 'secondary.attendance.manage', 'secondary.fees.validate'],
  dashboardFinance: ['secondary.finance.read', 'secondary.fees.read', 'secondary.billing.manage'],
  dashboardNetwork: ['secondary.network.read'],
  dashboardClaims: ['secondary.dashboard.read', 'secondary.grades.validate', 'secondary.settings.manage'],
  dashboardClaimsWrite: ['secondary.grades.validate', 'secondary.settings.manage'],
  grades: ['secondary.grades.read', 'secondary.grades.enter', 'secondary.grades.validate', 'secondary.grades.lock'],
  discipline: ['secondary.attendance.read', 'secondary.attendance.take', 'secondary.attendance.manage', 'secondary.discipline.manage', 'secondary.discipline.validate', 'secondary.discipline.council.preside'],
  disciplineCouncils: ['secondary.discipline.manage', 'secondary.discipline.validate', 'secondary.discipline.council.preside'],
  timetable: ['secondary.timetable.read', 'secondary.timetable.manage', 'secondary.timetable.validate', 'secondary.lesson.log'],
  syllabus: ['secondary.syllabus.read', 'secondary.syllabus.manage', 'secondary.syllabus.visa', 'secondary.lesson.log', 'secondary.inspection.read'],
  self: ['secondary.self.read'],
  family: ['secondary.family.read'],
  familyPay: ['secondary.family.pay'],
} as const;

/** Accès à l'espace : au moins une permission du secondaire. */
export const SPACE: readonly string[] = [...new Set(Object.values(S).flat())];
