import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { AdmissionType } from "@scholaris/prisma";
import { IsEnum, IsNotEmpty, IsNumber, IsObject, IsOptional, IsString, IsUUID } from "class-validator";

export class CreateAdmissionDto {
  @ApiProperty({ example: "Essomba Marie-Claire" })
  @IsString()
  @IsNotEmpty({ message: "Le nom du candidat est requis" })
  applicantName!: string;

  @ApiPropertyOptional({
    description: "Informations libres du dossier (date de naissance, école d'origine, contacts...)",
    type: Object,
  })
  @IsOptional()
  @IsObject()
  applicantInfo?: Record<string, unknown>;

  @ApiProperty({ enum: AdmissionType })
  @IsEnum(AdmissionType, { message: "Le type de candidature est requis (EXAM, DOSSIER ou DIRECT)" })
  type!: AdmissionType;

  @ApiPropertyOptional({ example: 14.25 })
  @IsOptional()
  @IsNumber()
  score?: number;

  @ApiProperty()
  @IsUUID(undefined, { message: "L'année académique est requise" })
  academicYearId!: string;
}
