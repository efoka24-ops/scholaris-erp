// Permissions des écrans du secondaire (miroir de la migration 202610281601_roles_secondary.sql). Le serveur reste juge des droits :
// ces listes adaptent seulement menus et boutons. L'élève et le parent lisent par leur propre périmètre (self.read, family.*).
export const S = {
  profileRead: ['secondary.profile.read', 'secondary.profile.manage', 'secondary.settings.manage', 'secondary.settings.read'],
  profileManage: ['secondary.profile.manage', 'secondary.settings.manage'],
  settingsRead: ['secondary.settings.read', 'secondary.settings.write', 'secondary.settings.manage'],
  enrolRead: ['secondary.enrol.read', 'secondary.enrol.manage', 'secondary.enrol.validate'],
  documentsRead: ['secondary.documents.issue', 'secondary.enrol.manage', 'secondary.enrol.read', 'secondary.billing.manage', 'secondary.settings.manage'],
  feesRead: ['secondary.fees.read', 'secondary.billing.manage', 'secondary.billing.cash', 'secondary.fees.catalog', 'secondary.finance.read'],
  carteScolaire: ['secondary.enrol.read', 'secondary.enrol.manage', 'secondary.billing.manage', 'secondary.fees.read', 'secondary.finance.read'],
  examsRead: ['secondary.exams.read', 'secondary.exams.manage', 'secondary.exams.lists'],
  orientation: ['secondary.orientation.manage'],
  workshops: ['secondary.workshops.read', 'secondary.workshops.manage', 'secondary.workshops.session'],
  internships: ['secondary.internships.manage'],
  payroll: ['secondary.finance.read', 'secondary.billing.manage'],
  services: ['secondary.services.manage', 'secondary.library.manage', 'secondary.infirmary.manage'],
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
