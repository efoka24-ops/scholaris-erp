import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { SubjectCategory } from "@scholaris/prisma";
import {
  IsArray,
  IsBoolean,
  IsEnum,
  IsInt,
  IsNotEmpty,
  IsNumber,
  IsOptional,
  IsPositive,
  IsString,
  IsUUID,
  Min,
} from "class-validator";

export class CreateSubjectDto {
  @ApiProperty({ example: "MATH" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ example: "Mathématiques" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiProperty({ example: 4 })
  @IsNumber({}, { message: "Le coefficient doit être un nombre" })
  @IsPositive({ message: "Le coefficient doit être strictement positif" })
  coefficient!: number;

  @ApiProperty({ example: 5, description: "Volume horaire hebdomadaire" })
  @IsInt({ message: "Le volume horaire doit être un entier" })
  @IsPositive({ message: "Le volume horaire hebdomadaire doit être positif" })
  weeklyHours!: number;

  @ApiProperty({ enum: SubjectCategory })
  @IsEnum(SubjectCategory, { message: "Catégorie invalide" })
  category!: SubjectCategory;

  @ApiPropertyOptional({ default: false })
  @IsOptional()
  @IsBoolean()
  isEliminatory?: boolean;

  @ApiPropertyOptional({ example: 7, description: "Seuil éliminatoire (0 = désactivé)" })
  @IsOptional()
  @IsNumber({}, { message: "Le seuil éliminatoire doit être un nombre" })
  @Min(0, { message: "Le seuil éliminatoire ne peut pas être négatif" })
  eliminatoryThreshold?: number;

  @ApiPropertyOptional({ type: [String], description: "Identifiants des niveaux concernés" })
  @IsOptional()
  @IsArray()
  @IsUUID(undefined, { each: true, message: "Chaque niveau doit être un identifiant valide" })
  levelIds?: string[];
}
