// Miroir backend de la matrice « Fonctionnalités par type d'établissement ».
// Sert au garde de catégorie (défense en profondeur : l'API renvoie 403 si un
// établissement accède à une fonctionnalité qui ne le concerne pas).

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

/** Regroupements pratiques réutilisables par les décorateurs @RequiresCategory. */
export const CATEGORY_GROUPS = {
  SECONDARY,
  SUPERIEUR_ONLY: ["SUPERIEUR"] as EstablishmentCategory[],
  EXAMS: ["PRIMAIRE", ...SECONDARY] as EstablishmentCategory[],
};

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
      return "COLLEGE";
  }
}
