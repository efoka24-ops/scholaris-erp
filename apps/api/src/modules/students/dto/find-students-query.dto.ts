import { ApiPropertyOptional } from "@nestjs/swagger";
import { StudentStatus } from "@scholaris/prisma";
import { Type } from "class-transformer";
import { IsEnum, IsInt, IsIn, IsOptional, IsString, IsUUID, Min } from "class-validator";

export class FindStudentsQueryDto {
  @ApiPropertyOptional({ default: 1 })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  page?: number;

  @ApiPropertyOptional({ default: 20 })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  limit?: number;

  @ApiPropertyOptional({ description: "Recherche sur nom, prénom ou matricule" })
  @IsOptional()
  @IsString()
  search?: string;

  @ApiPropertyOptional({ description: "Filtre par classe (inscription active)" })
  @IsOptional()
  @IsUUID()
  classroomId?: string;

  @ApiPropertyOptional({ description: "Filtre par niveau (via la classe d'inscription active)" })
  @IsOptional()
  @IsUUID()
  levelId?: string;

  @ApiPropertyOptional({ description: "Filtre par année académique de l'inscription" })
  @IsOptional()
  @IsUUID()
  academicYearId?: string;

  @ApiPropertyOptional({ enum: StudentStatus })
  @IsOptional()
  @IsEnum(StudentStatus)
  status?: StudentStatus;

  @ApiPropertyOptional({ enum: ["lastName", "firstName", "matricule", "createdAt"] })
  @IsOptional()
  @IsIn(["lastName", "firstName", "matricule", "createdAt"])
  sortBy?: "lastName" | "firstName" | "matricule" | "createdAt";

  @ApiPropertyOptional({ enum: ["asc", "desc"] })
  @IsOptional()
  @IsIn(["asc", "desc"])
  sortOrder?: "asc" | "desc";
}
