import { z } from "zod";

// Dupliqués volontairement des enums Prisma (packages/prisma) : ce package ne
// dépend pas du client Prisma généré, pour rester utilisable côté navigateur.
export const GENDERS = ["MALE", "FEMALE"] as const;
export const STUDENT_STATUSES = ["ACTIVE", "SUSPENDED", "GRADUATED", "EXCLUDED", "ABANDONED"] as const;
export const PARENT_RELATIONSHIPS = ["FATHER", "MOTHER", "GUARDIAN"] as const;
export const ENROLLMENT_TYPES = ["NEW", "RE_ENROLLMENT", "TRANSFER"] as const;
export const ENROLLMENT_STATUSES = ["PENDING", "ACTIVE", "CANCELLED"] as const;
export const ENROLLMENT_REGIMES = ["EXTERNAL", "HALF_BOARD", "BOARDING"] as const;
export const ADMISSION_TYPES = ["EXAM", "DOSSIER", "DIRECT"] as const;
export const ADMISSION_STATUSES = ["PENDING", "ACCEPTED", "REJECTED", "WAITLISTED"] as const;

export const parentSchema = z.object({
  parentId: z.string().uuid().optional(),
  firstName: z.string().min(1, "Le prénom du parent est requis").optional(),
  lastName: z.string().min(1, "Le nom du parent est requis").optional(),
  phone: z.string().min(1, "Le téléphone du parent est requis").optional(),
  whatsapp: z.string().optional(),
  email: z.string().email("Email invalide").optional().or(z.literal("")),
  profession: z.string().optional(),
  address: z.string().optional(),
  relationship: z.enum(PARENT_RELATIONSHIPS),
});
export type ParentInput = z.infer<typeof parentSchema>;

export const studentIdentitySchema = z.object({
  firstName: z.string().min(1, "Le prénom est requis"),
  lastName: z.string().min(1, "Le nom est requis"),
  dateOfBirth: z.string().min(1, "La date de naissance est requise"),
  placeOfBirth: z.string().optional(),
  gender: z.enum(GENDERS, { required_error: "Le sexe est requis" }),
  nationality: z.string().optional(),
  bloodGroup: z.string().optional(),
  allergies: z.string().optional(),
  handicap: z.string().optional(),
  emergencyContact: z.string().optional(),
});
export type StudentIdentityInput = z.infer<typeof studentIdentitySchema>;

export const enrollmentSchema = z.object({
  studentId: z.string().uuid("L'élève est requis"),
  classroomId: z.string().uuid("La classe est requise"),
  academicYearId: z.string().uuid("L'année académique est requise"),
  enrollmentDate: z.string().optional(),
  type: z.enum(ENROLLMENT_TYPES).default("NEW"),
  regime: z.enum(ENROLLMENT_REGIMES).default("EXTERNAL"),
  isRepeater: z.boolean().default(false),
  previousSchool: z.string().optional(),
  previousAverage: z.coerce.number().min(0).max(20).optional(),
});
export type EnrollmentInput = z.infer<typeof enrollmentSchema>;

export const admissionSchema = z.object({
  applicantName: z.string().min(1, "Le nom du candidat est requis"),
  type: z.enum(ADMISSION_TYPES),
  score: z.coerce.number().optional(),
  academicYearId: z.string().uuid("L'année académique est requise"),
  applicantInfo: z.record(z.unknown()).optional(),
});
export type AdmissionInput = z.infer<typeof admissionSchema>;
