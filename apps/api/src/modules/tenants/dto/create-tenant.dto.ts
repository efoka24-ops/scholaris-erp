import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { TenantStatus, TenantType } from "@scholaris/prisma";
import { IsEmail, IsEnum, IsNotEmpty, IsObject, IsOptional, IsString } from "class-validator";

/**
 * Création d'un établissement (Super Admin uniquement). Le champ `config`
 * (moteur de calcul) est validé côté service avec `calculationEngineSchema`
 * puis stocké au premier niveau de `Tenant.config_json`.
 */
export class CreateTenantDto {
  @ApiProperty({ example: "Lycée Bilingue de Maroua" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiProperty({ example: "EN/EXN/LBM" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ enum: TenantType, example: TenantType.SECONDAIRE })
  @IsEnum(TenantType, { message: "Type d'établissement invalide" })
  type!: TenantType;

  @ApiPropertyOptional({ enum: TenantStatus, example: TenantStatus.PUBLIC })
  @IsOptional()
  @IsEnum(TenantStatus, { message: "Statut invalide (PUBLIC ou PRIVE)" })
  status?: TenantStatus;

  @ApiPropertyOptional({ example: "Quartier Domayo, BP 46, Maroua, Extrême-Nord" })
  @IsOptional()
  @IsString()
  address?: string;

  @ApiPropertyOptional({ example: "+237699000001" })
  @IsOptional()
  @IsString()
  phone?: string;

  @ApiPropertyOptional({ example: "lbm@minesec.gov.cm" })
  @IsOptional()
  @IsEmail({}, { message: "Adresse email invalide" })
  email?: string;

  @ApiPropertyOptional({ description: "URL du logo" })
  @IsOptional()
  @IsString()
  logoUrl?: string;

  @ApiPropertyOptional({ description: "Configuration du moteur de calcul (validée côté serveur)" })
  @IsOptional()
  @IsObject()
  config?: unknown;
}
