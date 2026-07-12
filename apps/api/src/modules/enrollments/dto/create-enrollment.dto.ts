import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { EnrollmentRegime, EnrollmentType } from "@scholaris/prisma";
import { IsBoolean, IsDateString, IsEnum, IsNumber, IsObject, IsOptional, IsString, IsUUID } from "class-validator";

export class CreateEnrollmentDto {
  @ApiProperty()
  @IsUUID(undefined, { message: "L'élève est requis" })
  studentId!: string;

  @ApiProperty()
  @IsUUID(undefined, { message: "La classe est requise" })
  classroomId!: string;

  @ApiProperty()
  @IsUUID(undefined, { message: "L'année académique est requise" })
  academicYearId!: string;

  @ApiPropertyOptional({ example: "2026-09-01" })
  @IsOptional()
  @IsDateString({}, { message: "La date d'inscription est invalide" })
  enrollmentDate?: string;

  @ApiPropertyOptional({ enum: EnrollmentType, default: EnrollmentType.NEW })
  @IsOptional()
  @IsEnum(EnrollmentType)
  type?: EnrollmentType;

  @ApiPropertyOptional({ enum: EnrollmentRegime, default: EnrollmentRegime.EXTERNAL })
  @IsOptional()
  @IsEnum(EnrollmentRegime)
  regime?: EnrollmentRegime;

  @ApiPropertyOptional({ default: false })
  @IsOptional()
  @IsBoolean()
  isRepeater?: boolean;

  @ApiPropertyOptional({ example: "Collège Vogt" })
  @IsOptional()
  @IsString()
  previousSchool?: string;

  @ApiPropertyOptional({ example: 12.5 })
  @IsOptional()
  @IsNumber()
  previousAverage?: number;

  @ApiPropertyOptional({ description: "Pièces du dossier (acte de naissance, bulletins...)", type: Object })
  @IsOptional()
  @IsObject()
  documents?: Record<string, unknown>;
}
