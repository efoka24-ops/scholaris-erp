import { z } from "zod";

// Dupliqués volontairement des enums Prisma (packages/prisma) : ce package ne
// dépend pas du client Prisma généré, pour rester utilisable côté navigateur.
export const SECTIONS = ["FRANCOPHONE", "ANGLOPHONE"] as const;
export const GROUP_TYPES = ["TD", "TP", "LV2"] as const;
export const ROOM_TYPES = ["SALLE_CLASSE", "LABORATOIRE", "SALLE_INFO", "AMPHITHEATRE", "TERRAIN_SPORT"] as const;

export const cycleSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  // Auto-incrémenté côté serveur si omis (voir CyclesService.create).
  order: z.coerce.number().int().min(0).optional(),
});
export type CycleInput = z.infer<typeof cycleSchema>;

export const departmentSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  headTeacherId: z.string().uuid().optional().nullable(),
});
export type DepartmentInput = z.infer<typeof departmentSchema>;

export const programSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  cycleId: z.string().uuid("Le cycle est requis"),
  departmentId: z.string().uuid().optional().nullable(),
});
export type ProgramInput = z.infer<typeof programSchema>;

export const levelSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  // Auto-incrémenté côté serveur si omis (voir LevelsService.create).
  order: z.coerce.number().int().min(0).optional(),
  cycleId: z.string().uuid("Le cycle est requis"),
  programId: z.string().uuid().optional().nullable(),
});
export type LevelInput = z.infer<typeof levelSchema>;

export const roomSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  type: z.enum(ROOM_TYPES),
  capacity: z.coerce.number().int().positive().optional().nullable(),
  building: z.string().optional().nullable(),
  floor: z.string().optional().nullable(),
  equipment: z.array(z.string()).default([]),
});
export type RoomInput = z.infer<typeof roomSchema>;

export const classroomSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  capacity: z.coerce.number().int().positive("La capacité doit être positive"),
  levelId: z.string().uuid("Le niveau est requis"),
  mainTeacherId: z.string().uuid().optional().nullable(),
  roomId: z.string().uuid().optional().nullable(),
  section: z.enum(SECTIONS),
});
export type ClassroomInput = z.infer<typeof classroomSchema>;

export const groupSchema = z.object({
  code: z.string().min(1, "Le code est requis"),
  name: z.string().min(1, "Le nom est requis"),
  type: z.enum(GROUP_TYPES),
  classroomId: z.string().uuid("La classe est requise"),
});
export type GroupInput = z.infer<typeof groupSchema>;
