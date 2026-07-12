import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { ParentRelationship } from "@scholaris/prisma";
import { IsEmail, IsEnum, IsNotEmpty, IsOptional, IsString, IsUUID } from "class-validator";

/**
 * Parent d'un élève : soit un parent existant référencé par `parentId`
 * (fratrie), soit un parent créé à la volée avec ses coordonnées.
 */
export class CreateParentDto {
  @ApiPropertyOptional({ description: "Identifiant d'un parent existant à relier (fratrie)" })
  @IsOptional()
  @IsUUID()
  parentId?: string;

  @ApiPropertyOptional({ example: "Jean" })
  @IsOptional()
  @IsString()
  firstName?: string;

  @ApiPropertyOptional({ example: "Mbarga" })
  @IsOptional()
  @IsString()
  lastName?: string;

  @ApiPropertyOptional({ example: "+237690000001" })
  @IsOptional()
  @IsString()
  phone?: string;

  @ApiPropertyOptional({ example: "+237690000001" })
  @IsOptional()
  @IsString()
  whatsapp?: string;

  @ApiPropertyOptional({ example: "jean.mbarga@example.com" })
  @IsOptional()
  @IsEmail({}, { message: "L'adresse email du parent est invalide" })
  email?: string;

  @ApiPropertyOptional({ example: "Commerçant" })
  @IsOptional()
  @IsString()
  profession?: string;

  @ApiPropertyOptional({ example: "Quartier Bastos, Yaoundé" })
  @IsOptional()
  @IsString()
  address?: string;

  @ApiProperty({ enum: ParentRelationship })
  @IsEnum(ParentRelationship, { message: "Le lien de parenté est requis (FATHER, MOTHER ou GUARDIAN)" })
  relationship!: ParentRelationship;
}
