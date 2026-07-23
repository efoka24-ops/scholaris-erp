// Rôles système livrés par le seed (packages/prisma/src/seed.ts).
// Les rôles additionnels (Censeur, Enseignant, Comptable...) sont créés par établissement
// via /api/users et ne sont donc pas des constantes figées ici.
export const SYSTEM_ROLES = {
  SUPER_ADMIN: "SUPER_ADMIN",
  ADMIN: "ADMIN",
} as const;

export type SystemRole = (typeof SYSTEM_ROLES)[keyof typeof SYSTEM_ROLES];

// Convention resource:action reprise du guide de développement (§0.3).
export const PERMISSIONS = {
  USERS_CREATE: "users:create",
  USERS_READ: "users:read",
  USERS_UPDATE: "users:update",
  USERS_DELETE: "users:delete",
  TENANTS_CREATE: "tenants:create",
  TENANTS_READ: "tenants:read",
  TENANTS_UPDATE: "tenants:update",
  ROLES_CREATE: "roles:create",
  ROLES_READ: "roles:read",
  ROLES_UPDATE: "roles:update",
  ROLES_DELETE: "roles:delete",
  PERMISSIONS_READ: "permissions:read",
  ACADEMIC_YEARS_CREATE: "academic-years:create",
  ACADEMIC_YEARS_READ: "academic-years:read",
  ACADEMIC_YEARS_UPDATE: "academic-years:update",
  PERIODS_READ: "periods:read",
  PERIODS_CREATE: "periods:create",
  PERIODS_UPDATE: "periods:update",
  // Réservé au rôle Admin : rouvrir une période dont la saisie a été verrouillée.
  PERIODS_UNLOCK: "periods:unlock",
  AUDIT_LOGS_READ: "audit-logs:read",
  // Module 2 — Structure pédagogique
  CYCLES_READ: "cycles:read",
  CYCLES_CREATE: "cycles:create",
  DEPARTMENTS_READ: "departments:read",
  DEPARTMENTS_CREATE: "departments:create",
  DEPARTMENTS_UPDATE: "departments:update",
  PROGRAMS_READ: "programs:read",
  PROGRAMS_CREATE: "programs:create",
  PROGRAMS_UPDATE: "programs:update",
  LEVELS_READ: "levels:read",
  LEVELS_CREATE: "levels:create",
  LEVELS_UPDATE: "levels:update",
  LEVELS_DELETE: "levels:delete",
  CLASSROOMS_READ: "classrooms:read",
  CLASSROOMS_CREATE: "classrooms:create",
  CLASSROOMS_UPDATE: "classrooms:update",
  ROOMS_READ: "rooms:read",
  ROOMS_CREATE: "rooms:create",
  ROOMS_UPDATE: "rooms:update",
  STRUCTURE_READ: "structure:read",

  // Module 8 : Communication multicanal
  COMMUNICATION_TEMPLATES_CREATE: "communication-templates:create",
  COMMUNICATION_TEMPLATES_READ: "communication-templates:read",
  COMMUNICATION_TEMPLATES_UPDATE: "communication-templates:update",
  COMMUNICATIONS_CREATE: "communications:create",
  COMMUNICATIONS_READ: "communications:read",
  INTERNAL_MESSAGES_CREATE: "internal-messages:create",
  INTERNAL_MESSAGES_READ: "internal-messages:read",

  // Module 3 — Matières, UE et EC
  SUBJECTS_READ: "subjects:read",
  SUBJECTS_CREATE: "subjects:create",
  SUBJECTS_UPDATE: "subjects:update",
  SUBJECTS_DELETE: "subjects:delete",
  TEACHING_UNITS_READ: "teaching-units:read",
  TEACHING_UNITS_CREATE: "teaching-units:create",
  COURSE_ELEMENTS_READ: "course-elements:read",
  COURSE_ELEMENTS_CREATE: "course-elements:create",
  SUBJECT_ASSIGNMENTS_READ: "subject-assignments:read",
  SUBJECT_ASSIGNMENTS_CREATE: "subject-assignments:create",

  // Module 4 — Inscriptions & Admissions
  STUDENTS_CREATE: "students:create",
  STUDENTS_READ: "students:read",
  STUDENTS_UPDATE: "students:update",
  STUDENTS_IMPORT: "students:import",
  ENROLLMENTS_CREATE: "enrollments:create",
  ENROLLMENTS_READ: "enrollments:read",
  ENROLLMENTS_UPDATE: "enrollments:update",
  ENROLLMENTS_RE_ENROLL: "enrollments:re-enroll",
  ADMISSIONS_CREATE: "admissions:create",
  ADMISSIONS_READ: "admissions:read",
  ADMISSIONS_DECIDE: "admissions:decide",

  // Module 7 — Gestion financière
  FEE_STRUCTURES_CREATE: "fee-structures:create",
  FEE_STRUCTURES_READ: "fee-structures:read",
  INVOICES_CREATE: "invoices:create",
  INVOICES_READ: "invoices:read",
  PAYMENTS_CREATE: "payments:create",
  PAYMENTS_READ: "payments:read",
  DISCOUNTS_CREATE: "discounts:create",
  FINANCE_DASHBOARD_READ: "finance-dashboard:read",

  // Module 5 — Saisie des notes et moteur de calcul
  GRADES_CREATE: "grades:create",
  GRADES_READ: "grades:read",
  GRADES_UPDATE: "grades:update",
  GRADES_IMPORT: "grades:import",
  // Verrouiller sa propre saisie (enseignant, fin de saisie d'une évaluation).
  GRADES_LOCK: "grades:lock",
  // Rouvrir une saisie verrouillée (censeur/admin uniquement).
  GRADES_UNLOCK: "grades:unlock",
  // Calculer moyennes/classements (période ou bilan annuel).
  GRADES_CALCULATE: "grades:calculate",
  // Tableau d'avancement de la saisie (censeur).
  GRADES_PROGRESS: "grades:progress",
  GRADES_DELIBERATION: "grades:deliberation",
  // Rendre les résultats visibles aux parents/élèves (directeur/admin).
  GRADES_PUBLISH: "grades:publish",

  // Module 6 — Bulletins et diplômes
  BULLETINS_GENERATE: "bulletins:generate",
  BULLETINS_READ: "bulletins:read",
  BULLETINS_SEND: "bulletins:send",

  // Module 9 — Emplois du temps
  TIMETABLES_READ: "timetables:read",
  TIMETABLES_CREATE: "timetables:create",
  TIMETABLES_UPDATE: "timetables:update",
  TIMETABLES_DELETE: "timetables:delete",

  // Module 10 — Présences
  ATTENDANCE_READ: "attendance:read",
  ATTENDANCE_CREATE: "attendance:create",
  ATTENDANCE_UPDATE: "attendance:update",

  // Module 11 — Discipline
  DISCIPLINE_READ: "discipline:read",
  DISCIPLINE_CREATE: "discipline:create",

  // Module 12 — Vie scolaire (clubs, activités)
  SCHOOL_LIFE_READ: "school-life:read",
  SCHOOL_LIFE_CREATE: "school-life:create",

  // Module 14 — Bibliothèque
  LIBRARY_READ: "library:read",
  LIBRARY_CREATE: "library:create",
  LIBRARY_UPDATE: "library:update",

  // Module 15 — Transport scolaire
  TRANSPORT_READ: "transport:read",
  TRANSPORT_CREATE: "transport:create",

  // Module 16 — Cantine / internat
  CATERING_READ: "catering:read",
  CATERING_CREATE: "catering:create",

  // Module 17 — Patrimoine
  ASSETS_READ: "assets:read",
  ASSETS_CREATE: "assets:create",
  ASSETS_UPDATE: "assets:update",
  ASSETS_DELETE: "assets:delete",

  // Module 18 — RH & paie
  HR_READ: "hr:read",
  HR_CREATE: "hr:create",
  HR_UPDATE: "hr:update",

  // Gestion des utilisateurs — assignation de rôles
  USERS_ASSIGN_ROLES: "users:assign-roles",
} as const;

export type Permission = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];

export function toPermissionCode(resource: string, action: string): string {
  return `${resource}:${action}`;
}
