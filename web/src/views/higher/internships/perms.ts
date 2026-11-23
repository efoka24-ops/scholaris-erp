// Permissions des écrans « Stages et soutenances » (InternshipAccess, RoleMatrix). Le serveur contrôle le périmètre (403/404) :
// ces listes adaptent seulement menus et boutons.
export const S = {
  read: ['higher.internship.read', 'higher.internship.manage', 'higher.defense.manage', 'higher.document.sign_school'],
  manage: ['higher.internship.manage'],
  defense: ['higher.defense.manage'],
  signSchool: ['higher.document.sign_school'],
  jury: ['higher.jury.portal', 'higher.defense.manage', 'higher.internship.read'],
  student: ['self.read'],
} as const;
