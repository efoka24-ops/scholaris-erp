import { z } from "zod";

// Dupliqué volontairement de l'enum Prisma SubjectCategory (packages/prisma) :
// ce package ne dépend pas du client Prisma généré, pour rester utilisable
// côté navigateur (même convention que structure.schema.ts).
export const SUBJECT_CATEGORIES = ["LITERARY", "SCIENTIFIC", "TECHNICAL", "LANGUAGE", "SPORTS"] as const;
export type SubjectCategoryValue = (typeof SUBJECT_CATEGORIES)[number];

export const SUBJECT_CATEGORY_LABELS: Record<SubjectCategoryValue, string> = {
  LITERARY: "Littéraire",
  SCIENTIFIC: "Scientifique",
  TECHNICAL: "Technique",
  LANGUAGE: "Langue",
  SPORTS: "Sport",
};

export const subjectSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  coefficient: z.coerce.number().positive("Le coefficient doit être strictement positif"),
  weeklyHours: z.coerce.number().int().positive("Le volume horaire hebdomadaire doit être positif"),
  category: z.enum(SUBJECT_CATEGORIES),
  isEliminatory: z.coerce.boolean().default(false),
  // Seuil éliminatoire configurable ; 0 (défaut) = pas de seuil.
  eliminatoryThreshold: z.coerce.number().min(0, "Le seuil éliminatoire ne peut pas être négatif").optional(),
  levelIds: z.array(z.string().uuid()).default([]),
});
export type SubjectInput = z.infer<typeof subjectSchema>;

export const teachingUnitSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  credits: z.coerce.number().int().positive("Les crédits doivent être strictement positifs"),
  semester: z.coerce.number().int().min(1, "Le semestre est requis"),
  isFundamental: z.coerce.boolean().default(false),
  departmentId: z.string().uuid("Le département est requis"),
});
export type TeachingUnitInput = z.infer<typeof teachingUnitSchema>;

export const courseElementSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  credits: z.coerce.number().int().positive("Les crédits doivent être strictement positifs"),
  hoursCm: z.coerce.number().int().min(0).default(0),
  hoursTd: z.coerce.number().int().min(0).default(0),
  hoursTp: z.coerce.number().int().min(0).default(0),
  coefficient: z.coerce.number().positive("Le coefficient doit être strictement positif").default(1),
  teachingUnitId: z.string().uuid("L'unité d'enseignement est requise"),
});
export type CourseElementInput = z.infer<typeof courseElementSchema>;

export const subjectAssignmentSchema = z
  .object({
    subjectId: z.string().uuid().optional().nullable(),
    courseElementId: z.string().uuid().optional().nullable(),
    teacherId: z.string().uuid("L'enseignant est requis"),
    classroomId: z.string().uuid("La classe est requise"),
    academicYearId: z.string().uuid("L'année académique est requise"),
  })
  .refine((value) => Boolean(value.subjectId) !== Boolean(value.courseElementId), {
    message: "Renseigner soit une matière, soit un EC (exactement l'un des deux)",
    path: ["subjectId"],
  });
export type SubjectAssignmentInput = z.infer<typeof subjectAssignmentSchema>;

// Rapport renvoyé par POST /api/subjects/import.
export interface SubjectImportRowError {
  row: number;
  message: string;
}

export interface SubjectImportReport {
  created: number;
  errors: SubjectImportRowError[];
  // Lignes valides parsées (renvoyées telles quelles en mode dryRun pour la prévisualisation).
  rows: SubjectInput[];
}
