import { SetMetadata } from "@nestjs/common";

export const IS_PUBLIC_KEY = "isPublic";

/** Marque un endpoint comme accessible sans JWT (login, health, docs). */
export const Public = () => SetMetadata(IS_PUBLIC_KEY, true);
