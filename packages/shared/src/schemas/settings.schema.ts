import { z } from "zod";

// ─── Module 1 : Calendrier académique & configuration du moteur de calcul ───
// Dupliqués volontairement des enums Prisma (packages/prisma) : ce package ne
// dépend pas du client Prisma généré, pour rester utilisable côté navigateur.

export const ACADEMIC_YEAR_STATUSES = ["ACTIVE", "CLOSED", "ARCHIVED"] as const;
export const PERIOD_TYPES = ["SEQUENCE", "TRIMESTER", "SEMESTER"] as const;
export const GRADING_STATUSES = ["CLOSED", "OPEN", "LOCKED"] as const;

export const academicYearSchema = z
  .object({
    label: z.string().min(1, "Le libellé est requis (ex: 2026-2027)"),
    startDate: z.string().min(1, "La date de début est requise"),
    endDate: z.string().min(1, "La date de fin est requise"),
  })
  .refine((value) => new Date(value.endDate) > new Date(value.startDate), {
    message: "La date de fin doit être postérieure à la date de début",
    path: ["endDate"],
  });
export type AcademicYearInput = z.infer<typeof academicYearSchema>;

export const periodSchema = z
  .object({
    academicYearId: z.string().uuid("L'année académique est requise"),
    type: z.enum(PERIOD_TYPES),
    number: z.coerce.number().int().min(1, "Le numéro doit être supérieur ou égal à 1"),
    startDate: z.string().min(1, "La date de début est requise"),
    endDate: z.string().min(1, "La date de fin est requise"),
  })
  .refine((value) => new Date(value.endDate) > new Date(value.startDate), {
    message: "La date de fin doit être postérieure à la date de début",
    path: ["endDate"],
  });
export type PeriodInput = z.infer<typeof periodSchema>;

// ─── Configuration du moteur de calcul (Tenant.config_json) ─────────────────
// SEQUENTIAL : séquences camerounaises agrégées par trimestre (secondaire général)
// TRIMESTER  : moyennes trimestrielles directes (primaire)
// SEMESTER   : semestres (technique / formation professionnelle)
// LMD        : système universitaire (UE/EC, crédits, compensation, GPA)

export const EVALUATION_TYPES = ["SEQUENTIAL", "TRIMESTER", "SEMESTER", "LMD"] as const;
export const ROUNDING_RULES = ["NONE", "HUNDREDTH", "TENTH", "HALF_POINT", "INTEGER"] as const;
export const ABSENCE_RULES = ["ZERO", "NEUTRALIZED", "POSTPONED"] as const;

export const mentionThresholdSchema = z.object({
  code: z.string().min(1, "Le code de la mention est requis"),
  label: z.string().min(1, "Le libellé de la mention est requis"),
  minAverage: z.coerce
    .number()
    .min(0, "Le seuil minimal est 0")
    .max(20, "Le seuil maximal est 20"),
});
export type MentionThreshold = z.infer<typeof mentionThresholdSchema>;

export const gpaScaleEntrySchema = z.object({
  grade: z.string().min(1, "La lettre de grade est requise (ex: A, B+)"),
  minScore: z.coerce.number().min(0).max(100),
  points: z.coerce.number().min(0).max(4),
});
export type GpaScaleEntry = z.infer<typeof gpaScaleEntrySchema>;

export const calculationEngineSchema = z
  .object({
    evaluationType: z.enum(EVALUATION_TYPES),
    /** Pondérations des séquences au sein d'un trimestre (ex: [1, 1]). */
    sequenceWeights: z.array(z.coerce.number().positive("Chaque pondération doit être positive")).optional(),
    /** Pondérations des trimestres dans la moyenne annuelle (ex: [1, 1, 1]). */
    trimesterWeights: z.array(z.coerce.number().positive("Chaque pondération doit être positive")).optional(),
    roundingRule: z.enum(ROUNDING_RULES),
    absenceRule: z.enum(ABSENCE_RULES),
    /** Seuils de mentions triés par l'appelant (ex: Très Bien ≥ 16). */
    mentionThresholds: z.array(mentionThresholdSchema).min(1, "Au moins une mention est requise"),
    /** LMD : compensation entre EC/UE autorisée. */
    lmdCompensation: z.boolean().optional(),
    /** LMD : échelle de conversion note → points GPA. */
    gpaScale: z.array(gpaScaleEntrySchema).optional(),
  })
  .strict()
  .superRefine((config, ctx) => {
    if (config.evaluationType === "SEQUENTIAL" && (!config.sequenceWeights || config.sequenceWeights.length === 0)) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ["sequenceWeights"],
        message: "Les pondérations des séquences sont requises en mode séquentiel",
      });
    }
    if (
      (config.evaluationType === "SEQUENTIAL" || config.evaluationType === "TRIMESTER") &&
      (!config.trimesterWeights || config.trimesterWeights.length === 0)
    ) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ["trimesterWeights"],
        message: "Les pondérations des trimestres sont requises pour ce type d'évaluation",
      });
    }
    if (config.evaluationType === "LMD" && (!config.gpaScale || config.gpaScale.length === 0)) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ["gpaScale"],
        message: "L'échelle GPA est requise en mode LMD",
      });
    }
  });
export type CalculationEngineConfig = z.infer<typeof calculationEngineSchema>;

/** Configuration par défaut proposée à un établissement secondaire camerounais. */
export const DEFAULT_CALCULATION_ENGINE_CONFIG: CalculationEngineConfig = {
  evaluationType: "SEQUENTIAL",
  sequenceWeights: [1, 1],
  trimesterWeights: [1, 1, 1],
  roundingRule: "HUNDREDTH",
  absenceRule: "ZERO",
  mentionThresholds: [
    { code: "EXCELLENT", label: "Excellent", minAverage: 18 },
    { code: "TRES_BIEN", label: "Très Bien", minAverage: 16 },
    { code: "BIEN", label: "Bien", minAverage: 14 },
    { code: "ASSEZ_BIEN", label: "Assez Bien", minAverage: 12 },
    { code: "PASSABLE", label: "Passable", minAverage: 10 },
    { code: "INSUFFISANT", label: "Insuffisant", minAverage: 0 },
  ],
};

// ─── Mise à jour de l'établissement (PUT /tenants/:id) ──────────────────────

export const tenantUpdateSchema = z.object({
  name: z.string().min(1, "Le nom est requis").optional(),
  address: z.string().optional().nullable(),
  phone: z.string().optional().nullable(),
  email: z.string().email("Adresse email invalide").optional().nullable(),
  logoUrl: z.string().url("URL de logo invalide").optional().nullable(),
});
export type TenantUpdateInput = z.infer<typeof tenantUpdateSchema>;
