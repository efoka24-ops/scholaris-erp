import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { GradeType } from "@scholaris/prisma";
import { IsDateString, IsEnum, IsNotEmpty, IsNumber, IsOptional, IsPositive, IsString, IsUUID } from "class-validator";

/**
 * Import Excel des notes d'une évaluation (une feuille = une évaluation).
 * Le fichier transite en base64 (même convention que ImportStudentsDto) :
 * le proxy générique `/api/proxy/**` du frontend ne transporte que du JSON.
 * Colonnes attendues : Matricule, Note (+ éventuellement Absent, Justifié).
 */
export class ImportGradesDto {
  @ApiPropertyOptional({ example: "notes-maths-sequence1.xlsx" })
  @IsOptional()
  @IsString()
  filename?: string;

  @ApiProperty({ description: "Contenu du fichier .xlsx encodé en base64" })
  @IsString()
  @IsNotEmpty({ message: "Le contenu du fichier est requis" })
  contentBase64!: string;

  @ApiProperty()
  @IsUUID(undefined, { message: "La classe est requise" })
  classroomId!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID(undefined, { message: "Identifiant de matière invalide" })
  subjectId?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID(undefined, { message: "Identifiant d'EC invalide" })
  courseElementId?: string;

  @ApiProperty()
  @IsUUID(undefined, { message: "La période est requise" })
  periodId!: string;

  @ApiProperty({ enum: GradeType, default: GradeType.TEST })
  @IsEnum(GradeType, { message: "Type de note invalide" })
  type!: GradeType;

  @ApiPropertyOptional({ example: "2026-03-10" })
  @IsOptional()
  @IsDateString({}, { message: "Date invalide" })
  date?: string;

  @ApiProperty({ example: 20, default: 20 })
  @IsNumber({}, { message: "Le barème doit être un nombre" })
  @IsPositive({ message: "Le barème doit être strictement positif" })
  maxValue!: number;

  @ApiPropertyOptional({ example: 1, default: 1 })
  @IsOptional()
  @IsNumber({}, { message: "Le poids doit être un nombre" })
  @IsPositive({ message: "Le poids doit être strictement positif" })
  weight?: number;
}
