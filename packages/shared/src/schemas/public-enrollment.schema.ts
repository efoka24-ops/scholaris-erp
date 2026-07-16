import { z } from "zod";

/**
 * Formulaire de pré-inscription publique (page vitrine, sans authentification).
 * Miroir du DTO backend `CreatePublicAdmissionDto`
 * (`apps/api/src/modules/admissions/dto/create-public-admission.dto.ts`).
 */
export const publicAdmissionSchema = z.object({
  tenantCode: z.string().min(1, "Le code établissement est requis"),
  studentLastName: z.string().min(1, "Le nom de l'élève est requis"),
  studentFirstName: z.string().min(1, "Le prénom de l'élève est requis"),
  studentDateOfBirth: z.string().min(1, "La date de naissance est requise"),
  desiredLevel: z.string().min(1, "Le niveau souhaité est requis"),
  parentName: z.string().min(1, "Le nom du parent / tuteur est requis"),
  parentPhone: z.string().min(1, "Le téléphone du parent / tuteur est requis"),
  parentEmail: z.string().email("Adresse email invalide").optional().or(z.literal("")),
  previousSchool: z.string().optional(),
  // Honeypot anti-bot : doit rester vide, jamais affiché à l'utilisateur.
  website: z.string().optional(),
});
export type PublicAdmissionInput = z.infer<typeof publicAdmissionSchema>;
