import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { EnrollmentRegime } from "@scholaris/prisma";
import { IsEnum, IsOptional, IsUUID } from "class-validator";

export class ReEnrollDto {
  @ApiProperty({ description: "Classe source (année précédente)" })
  @IsUUID(undefined, { message: "La classe source est requise" })
  sourceClassroomId!: string;

  @ApiProperty({ description: "Classe de destination (nouvelle année)" })
  @IsUUID(undefined, { message: "La classe de destination est requise" })
  targetClassroomId!: string;

  @ApiProperty({ description: "Année académique de destination" })
  @IsUUID(undefined, { message: "L'année académique de destination est requise" })
  targetAcademicYearId!: string;

  @ApiPropertyOptional({ enum: EnrollmentRegime, description: "Régime appliqué (sinon celui de l'inscription source)" })
  @IsOptional()
  @IsEnum(EnrollmentRegime)
  regime?: EnrollmentRegime;
}
