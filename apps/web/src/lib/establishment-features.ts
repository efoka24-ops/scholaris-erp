// Filtrage des menus selon le TYPE d'établissement (matrice « Fonctionnalités par
// type », TRU GROUP). Chaque établissement ne voit que les menus qui le concernent.
//
// Le type Prisma est grossier (PRIMAIRE, SECONDAIRE, SUPERIEUR, TECHNIQUE,
// FORMATION_PRO). On en dérive une CATÉGORIE à 6 valeurs. Pour la sidebar,
// Collège / Lycée Général / Lycée Technique partagent les mêmes menus (la
// différence BEPC/BAC/séries vit DANS les modules, pas dans les menus), donc la
// distinction fine n'est nécessaire que plus tard (contenu des modules).

export type EstablishmentCategory =
  | "PRIMAIRE"
  | "COLLEGE"
  | "LYCEE_GENERAL"
  | "LYCEE_TECHNIQUE"
  | "CENTRE_FORMATION"
  | "SUPERIEUR";

export const ALL_CATEGORIES: EstablishmentCategory[] = [
  "PRIMAIRE",
  "COLLEGE",
  "LYCEE_GENERAL",
  "LYCEE_TECHNIQUE",
  "CENTRE_FORMATION",
  "SUPERIEUR",
];

const SECONDARY: EstablishmentCategory[] = ["COLLEGE", "LYCEE_GENERAL", "LYCEE_TECHNIQUE"];

/**
 * Visibilité par href. Un href ABSENT de cette table est visible pour TOUS les
 * types (cas par défaut : dashboard, élèves, inscriptions, notes, finance,
 * vie scolaire, communication, rapports, config commune…). Seuls les menus
 * réellement spécifiques à un type sont listés ici.
 */
export const MENU_VISIBILITY: Record<string, EstablishmentCategory[]> = {
  // Matières : masqué au Supérieur (qui utilise UE/EC à la place)
  "/academics/subjects": ["PRIMAIRE", ...SECONDARY, "CENTRE_FORMATION"],
  // UE & EC : exclusivement le Supérieur (LMD)
  "/academics/teaching-units": ["SUPERIEUR"],
  // Groupes de matières A→E sur bulletin : Collège & Lycées uniquement
  "/settings/bulletin-groups": [...SECONDARY],
  // Examens officiels (CEP/BEPC/Probatoire/BAC) : primaire + secondaire.
  // Centre de formation & Supérieur : configurable (masqué par défaut ici).
  "/exams": ["PRIMAIRE", ...SECONDARY],
};

/** Menus réservés au Super Admin plateforme (gérés via la permission tenants:create). */
export const SUPER_ADMIN_ONLY_HREFS = new Set<string>([
  "/settings/establishments",
  "/settings/establishment-requests",
]);

/**
 * Modules OPTIONNELS (⚙️) que l'Admin active/désactive dans Paramètres → Modules.
 * Modèle : tant qu'aucune config n'existe (liste vide), tout est visible
 * (comportement actuel préservé) ; dès que l'Admin enregistre une sélection,
 * seuls les modules cochés restent affichés.
 */
export interface OptionalModule {
  key: string;
  href: string;
  label: string;
}
export const OPTIONAL_MODULES: OptionalModule[] = [
  { key: "health", href: "/health", label: "Santé scolaire" },
  { key: "discipline", href: "/discipline", label: "Discipline" },
  { key: "clubs", href: "/school-life/clubs", label: "Clubs & Activités" },
  { key: "library", href: "/library", label: "Bibliothèque" },
  { key: "transport", href: "/transport", label: "Transport" },
  { key: "catering", href: "/catering", label: "Cantine & Internat" },
  { key: "assets", href: "/assets", label: "Patrimoine" },
];
const OPTIONAL_HREF_TO_KEY = new Map<string, string>(OPTIONAL_MODULES.map((m) => [m.href, m.key]));

/** Terme utilisé pour « Élèves » selon la catégorie (matrice §6). */
export function learnerTerm(category: EstablishmentCategory): string {
  if (category === "SUPERIEUR") return "Étudiants";
  if (category === "CENTRE_FORMATION") return "Apprenants";
  return "Élèves";
}

/** Adapte le libellé d'un menu selon la catégorie (🔄 de la matrice). */
export function adaptLabel(href: string, defaultLabel: string, category: EstablishmentCategory): string {
  if (href === "/students") return learnerTerm(category);
  // Centre de formation : « Matières » → « Modules »
  if (href === "/academics/subjects" && category === "CENTRE_FORMATION") return "Modules";
  return defaultLabel;
}

/** Adapte le libellé d'une section de menu selon la catégorie. */
export function adaptSectionLabel(label: string, category: EstablishmentCategory): string {
  if (label === "Élèves") return learnerTerm(category);
  return label;
}

/** Un menu optionnel est-il visible ? (non optionnel → toujours ; sinon selon la config) */
export function isOptionalVisible(href: string, enabledModules: string[] | null | undefined): boolean {
  const key = OPTIONAL_HREF_TO_KEY.get(href);
  if (!key) return true; // pas un module optionnel
  if (!enabledModules || enabledModules.length === 0) return true; // aucune config → tout visible
  return enabledModules.includes(key);
}

/**
 * Permission (lecture) requise pour VOIR un menu. Un href absent → visible sans
 * permission particulière (dashboard, profil…). Combiné au type d'établissement
 * et aux modules optionnels, ceci masque les menus qu'un rôle ne peut pas utiliser.
 */
export const MENU_PERMISSIONS: Record<string, string> = {
  "/academics/structure": "structure:read",
  "/academics/classrooms": "classrooms:read",
  "/academics/rooms": "rooms:read",
  "/academics/subjects": "subjects:read",
  "/academics/teaching-units": "teaching-units:read",
  "/academics/assignments": "subject-assignments:read",
  "/settings/academic-years": "academic-years:read",
  "/students": "students:read",
  "/admissions": "admissions:read",
  "/enrollments": "enrollments:read",
  "/grades/entry": "grades:read",
  "/grades/calculations": "grades:read",
  "/bulletins": "bulletins:read",
  "/finance/dashboard": "finance-dashboard:read",
  "/finance/fee-structures": "fee-structures:read",
  "/finance/invoices": "invoices:read",
  "/finance/payments": "payments:read",
  "/exams": "exams:read",
  "/reports/level": "reports:read",
  "/timetables": "timetables:read",
  "/attendance": "attendance:read",
  "/discipline": "discipline:read",
  "/school-life/clubs": "school-life:read",
  "/library": "library:read",
  "/transport": "transport:read",
  "/catering": "catering:read",
  "/assets": "assets:read",
  "/hr": "hr:read",
  "/communications": "communications:read",
  "/communications/templates": "communication-templates:read",
  "/settings/users": "users:read",
  "/settings/roles": "roles:read",
  "/settings/calculation-engine": "tenants:read",
  "/settings/bulletin-groups": "tenants:read",
  "/settings/modules": "tenants:read",
  "/settings/establishment": "tenants:read",
  "/settings/audit-logs": "audit-logs:read",
};

/** Le rôle courant a-t-il la permission de voir ce menu ? (non listé → oui) */
export function hasMenuPermission(href: string, hasPermission: (p: string) => boolean): boolean {
  const perm = MENU_PERMISSIONS[href];
  return perm ? hasPermission(perm) : true;
}

/** Déduit la catégorie à 6 valeurs depuis le type Prisma (+ override éventuel en config). */
export function resolveCategory(type?: string | null, configCategory?: string | null): EstablishmentCategory {
  if (configCategory && (ALL_CATEGORIES as string[]).includes(configCategory)) {
    return configCategory as EstablishmentCategory;
  }
  switch (type) {
    case "PRIMAIRE":
      return "PRIMAIRE";
    case "SUPERIEUR":
      return "SUPERIEUR";
    case "TECHNIQUE":
      return "LYCEE_TECHNIQUE";
    case "FORMATION_PRO":
      return "CENTRE_FORMATION";
    case "SECONDAIRE":
    default:
      // Collège et Lycée partagent la même visibilité de menus.
      return "COLLEGE";
  }
}

/**
 * Un menu (href) est-il visible pour cette catégorie ?
 * - Le Super Admin (isSuperAdmin) voit tout.
 * - Les menus « plateforme » ne s'affichent que pour le Super Admin.
 * - Sinon : visible si non listé, ou si la catégorie est autorisée.
 */
export function isMenuVisible(href: string, category: EstablishmentCategory, isSuperAdmin: boolean): boolean {
  if (SUPER_ADMIN_ONLY_HREFS.has(href)) return isSuperAdmin;
  if (isSuperAdmin) return true;
  const allowed = MENU_VISIBILITY[href];
  return allowed ? allowed.includes(category) : true;
}
