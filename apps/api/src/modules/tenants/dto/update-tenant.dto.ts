import { ApiPropertyOptional } from "@nestjs/swagger";
import { IsBoolean, IsEmail, IsNotEmpty, IsOptional, IsString, IsUrl } from "class-validator";

export class UpdateTenantDto {
  @ApiPropertyOptional({ example: "Lycée Bilingue de Yaoundé" })
  @IsOptional()
  @IsString()
  @IsNotEmpty({ message: "Le nom ne peut pas être vide" })
  name?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  address?: string;

  @ApiPropertyOptional({ example: "+237 6 90 00 00 00" })
  @IsOptional()
  @IsString()
  phone?: string;

  @ApiPropertyOptional({ example: "contact@lycee.cm" })
  @IsOptional()
  @IsEmail({}, { message: "Adresse email invalide" })
  email?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUrl({}, { message: "URL de logo invalide" })
  logoUrl?: string;

  @ApiPropertyOptional({ description: "Visible dans l'annuaire public de pré-inscription en ligne" })
  @IsOptional()
  @IsBoolean()
  publicEnrollmentEnabled?: boolean;
}
