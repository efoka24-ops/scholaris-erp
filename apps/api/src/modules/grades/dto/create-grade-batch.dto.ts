import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { GradeType } from "@scholaris/prisma";
import { Type } from "class-transformer";
import {
  ArrayMinSize,
  IsArray,
  IsDateString,
  IsEnum,
  IsNumber,
  IsOptional,
  IsPositive,
  IsString,
  IsUUID,
  ValidateNested,
} from "class-validator";
import { GradeEntryDto } from "./grade-entry.dto";

/**
 * Saisie collective : une évaluation (matière OU EC, période, type, barème,
 * pondération) et une note par élève de la classe. Exactement l'un de
 * `subjectId` / `courseElementId` doit être renseigné (contrôlé côté service).
 */
export class CreateGradeBatchDto {
  @ApiProperty({ description: "Classe concernée (sert à vérifier les inscriptions actives)" })
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

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  comment?: string;

  @ApiProperty({ type: [GradeEntryDto] })
  @IsArray()
  @ArrayMinSize(1, { message: "Au moins une note est requise" })
  @ValidateNested({ each: true })
  @Type(() => GradeEntryDto)
  entries!: GradeEntryDto[];
}
