import { SetMetadata } from "@nestjs/common";
import type { EstablishmentCategory } from "../establishment/establishment-features";

export const REQUIRES_CATEGORY_KEY = "requiresCategory";

/**
 * Restreint une route/un contrôleur aux catégories d'établissement indiquées.
 * Le Super Admin (permission tenants:create) n'est jamais bloqué.
 * Ex: @RequiresCategory("SUPERIEUR") sur le contrôleur UE/EC.
 */
export const RequiresCategory = (...categories: EstablishmentCategory[]) =>
  SetMetadata(REQUIRES_CATEGORY_KEY, categories);
