import { ApiPropertyOptional } from "@nestjs/swagger";
import { SubjectCategory } from "@scholaris/prisma";
import { Type } from "class-transformer";
import { IsEnum, IsInt, IsOptional, IsPositive, IsString, IsUUID } from "class-validator";

export class FindSubjectsQueryDto {
  @ApiPropertyOptional({ enum: SubjectCategory })
  @IsOptional()
  @IsEnum(SubjectCategory, { message: "Catégorie invalide" })
  category?: SubjectCategory;

  @ApiPropertyOptional({ description: "Filtre : matières enseignées à ce niveau" })
  @IsOptional()
  @IsUUID(undefined, { message: "Le niveau doit être un identifiant valide" })
  levelId?: string;

  @ApiPropertyOptional({ description: "Recherche sur le code ou le nom" })
  @IsOptional()
  @IsString()
  search?: string;

  @ApiPropertyOptional({ default: 1 })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @IsPositive()
  page?: number;

  @ApiPropertyOptional({ default: 20 })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @IsPositive()
  limit?: number;
}
