import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsOptional, IsUUID } from "class-validator";

export class CreateSubjectAssignmentDto {
  @ApiPropertyOptional({ description: "Matière (secondaire) — exclusif avec courseElementId" })
  @IsOptional()
  @IsUUID(undefined, { message: "La matière doit être un identifiant valide" })
  subjectId?: string;

  @ApiPropertyOptional({ description: "EC (supérieur LMD) — exclusif avec subjectId" })
  @IsOptional()
  @IsUUID(undefined, { message: "L'EC doit être un identifiant valide" })
  courseElementId?: string;

  @ApiProperty()
  @IsUUID(undefined, { message: "L'enseignant est requis" })
  teacherId!: string;

  @ApiProperty()
  @IsUUID(undefined, { message: "La classe est requise" })
  classroomId!: string;

  @ApiProperty()
  @IsUUID(undefined, { message: "L'année académique est requise" })
  academicYearId!: string;
}
