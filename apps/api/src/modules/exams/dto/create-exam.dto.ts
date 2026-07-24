import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { OfficialExamCode } from "@scholaris/prisma";
import {
  IsBoolean,
  IsDateString,
  IsEnum,
  IsInt,
  IsNotEmpty,
  IsNumber,
  IsOptional,
  IsString,
  IsObject,
  Min,
} from "class-validator";

/**
 * Création d'un examen officiel (CEP/BEPC/Probatoire/BAC) ou configurable.
 * Les dates sont acceptées en ISO ou date-only et coercées côté service.
 */
export class CreateExamDto {
  @ApiProperty({ example: "BEPC Session 2027" })
  @IsString()
  @IsNotEmpty({ message: "Le nom de l'examen est requis" })
  name!: string;

  @ApiProperty({ enum: OfficialExamCode, example: OfficialExamCode.BEPC })
  @IsEnum(OfficialExamCode, { message: "Code d'examen invalide" })
  code!: OfficialExamCode;

  @ApiPropertyOptional({ description: "Niveau concerné (ex: 3ème)" })
  @IsOptional()
  @IsString()
  levelId?: string;

  @ApiProperty({ description: "Année académique de l'examen" })
  @IsString()
  @IsNotEmpty({ message: "L'année académique est requise" })
  academicYearId!: string;

  @ApiProperty({ example: "2026-12-01" })
  @IsDateString({}, { message: "Date de début d'inscription invalide" })
  registrationStart!: string;

  @ApiProperty({ example: "2027-01-31" })
  @IsDateString({}, { message: "Date de fin d'inscription invalide" })
  registrationEnd!: string;

  @ApiPropertyOptional({ example: "2027-05-20" })
  @IsOptional()
  @IsDateString({}, { message: "Date de début d'examen invalide" })
  examStart?: string;

  @ApiPropertyOptional({ example: "2027-05-24" })
  @IsOptional()
  @IsDateString({}, { message: "Date de fin d'examen invalide" })
  examEnd?: string;

  @ApiPropertyOptional({ example: 5000, description: "Frais d'examen (FCFA)" })
  @IsOptional()
  @IsNumber({}, { message: "Montant des frais invalide" })
  @Min(0)
  feeAmount?: number;

  @ApiPropertyOptional({ example: null })
  @IsOptional()
  @IsInt()
  @Min(0)
  minAge?: number;

  @ApiPropertyOptional({ example: 20 })
  @IsOptional()
  @IsInt()
  @Min(0)
  maxAge?: number;

  @ApiPropertyOptional({ example: 4, description: "Nb minimum de séquences composées" })
  @IsOptional()
  @IsInt()
  @Min(0)
  requiredSequences?: number;

  @ApiPropertyOptional({ example: 10, description: "Moyenne d'admission (défaut 10/20)" })
  @IsOptional()
  @IsNumber()
  @Min(0)
  passMark?: number;

  @ApiPropertyOptional({ example: 8, description: "Seuil d'oral de rattrapage (BAC)" })
  @IsOptional()
  @IsNumber()
  @Min(0)
  oralMinMark?: number;

  @ApiPropertyOptional({ example: true, description: "Examen d'État officiel (vs configurable)" })
  @IsOptional()
  @IsBoolean()
  isOfficial?: boolean;

  @ApiPropertyOptional({ description: "Config par série : matières et coefficients" })
  @IsOptional()
  @IsObject()
  config?: unknown;
}
