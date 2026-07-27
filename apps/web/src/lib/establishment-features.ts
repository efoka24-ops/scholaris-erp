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
