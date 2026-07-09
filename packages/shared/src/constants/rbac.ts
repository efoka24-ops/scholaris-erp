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
  TENANTS_READ: "tenants:read",
  TENANTS_UPDATE: "tenants:update",
  ACADEMIC_YEARS_CREATE: "academic-years:create",
  ACADEMIC_YEARS_READ: "academic-years:read",
  PERIODS_UPDATE: "periods:update",
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
} as const;

export type Permission = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];

export function toPermissionCode(resource: string, action: string): string {
  return `${resource}:${action}`;
}
