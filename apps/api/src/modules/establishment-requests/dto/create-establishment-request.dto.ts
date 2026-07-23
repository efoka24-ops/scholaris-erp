import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { TenantStatus, TenantType } from "@scholaris/prisma";
import { IsEmail, IsEnum, IsNotEmpty, IsOptional, IsString } from "class-validator";

/**
 * Demande publique de création d'établissement, déposée par un directeur sans
 * authentification (analogue de la pré-inscription parent). Elle est mise en
 * attente jusqu'à validation par le Super Admin.
 */
export class CreateEstablishmentRequestDto {
  @ApiProperty({ example: "Lycée Bilingue de Maroua" })
  @IsString()
  @IsNotEmpty({ message: "Le nom de l'établissement est requis" })
  name!: string;

  @ApiProperty({ example: "EN/EXN/LBM" })
  @IsString()
  @IsNotEmpty({ message: "Le code de l'établissement est requis" })
  code!: string;

  @ApiProperty({ enum: TenantType })
  @IsEnum(TenantType, { message: "Type d'établissement invalide" })
  type!: TenantType;

  @ApiPropertyOptional({ enum: TenantStatus })
  @IsOptional()
  @IsEnum(TenantStatus, { message: "Statut invalide (PUBLIC ou PRIVE)" })
  status?: TenantStatus;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  address?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  phone?: string;

  @ApiPropertyOptional({ example: "lbm@minesec.gov.cm" })
  @IsOptional()
  @IsEmail({}, { message: "Adresse email de l'établissement invalide" })
  email?: string;

  @ApiProperty({ example: "Jean" })
  @IsString()
  @IsNotEmpty({ message: "Le prénom du directeur est requis" })
  directorFirstName!: string;

  @ApiProperty({ example: "Directeur" })
  @IsString()
  @IsNotEmpty({ message: "Le nom du directeur est requis" })
  directorLastName!: string;

  @ApiProperty({ example: "directeur@lbm.cm" })
  @IsEmail({}, { message: "Adresse email du directeur invalide" })
  directorEmail!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  directorPhone?: string;

  @ApiPropertyOptional({
    description: "Champ honeypot anti-bot : doit rester vide.",
  })
  @IsOptional()
  @IsString()
  website?: string;
}
