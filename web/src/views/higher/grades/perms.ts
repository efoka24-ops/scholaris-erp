// Permissions des écrans « Notes » (MarkPolicy, RoleMatrix). Le serveur contrôle le périmètre (403/404) : ces listes adaptent
// seulement menus et boutons.
export const G = {
  read: ['higher.grades.read', 'assessment.enter', 'assessment.validate', 'assessment.correction.approve', 'higher.exam.manage', 'higher.anonymity.lift', 'higher.grades.publish'],
  enter: ['assessment.enter'],
  submit: ['higher.grades.submit'],
  validate: ['assessment.validate'],
  publish: ['higher.grades.publish'],
  lift: ['higher.anonymity.lift'],
  correctionApprove: ['assessment.correction.approve'],
  corrections: ['assessment.enter', 'assessment.correction.approve', 'higher.grades.read'],
  queries: ['assessment.validate', 'higher.grades.read'],
  progress: ['higher.grades.read', 'assessment.validate', 'assessment.correction.approve', 'higher.exam.manage', 'higher.grades.publish'],
  student: ['higher.query.create', 'self.read'],
} as const;
