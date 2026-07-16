import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsEmail, IsNotEmpty, IsOptional, IsString, Matches } from "class-validator";

/**
 * Formulaire de pré-inscription rempli par un parent, sans authentification
 * (page publique "vitrine"). Réutilise le modèle AdmissionApplication existant
 * (Module 4) avec type = DOSSIER et status = PENDING par défaut.
 */
export class CreatePublicAdmissionDto {
  @ApiProperty({ example: "DEMO", description: "Code public de l'établissement (Tenant.code)" })
  @IsString()
  @IsNotEmpty({ message: "Le code établissement est requis" })
  tenantCode!: string;

  @ApiProperty({ example: "Essomba" })
  @IsString()
  @IsNotEmpty({ message: "Le nom de l'élève est requis" })
  studentLastName!: string;

  @ApiProperty({ example: "Marie-Claire" })
  @IsString()
  @IsNotEmpty({ message: "Le prénom de l'élève est requis" })
  studentFirstName!: string;

  @ApiProperty({ example: "2015-03-12" })
  @IsString()
  @IsNotEmpty({ message: "La date de naissance est requise" })
  @Matches(/^\d{4}-\d{2}-\d{2}$/, { message: "La date de naissance doit être au format AAAA-MM-JJ" })
  studentDateOfBirth!: string;

  @ApiProperty({ example: "6ème" })
  @IsString()
  @IsNotEmpty({ message: "Le niveau souhaité est requis" })
  desiredLevel!: string;

  @ApiProperty({ example: "Essomba Jean" })
  @IsString()
  @IsNotEmpty({ message: "Le nom du parent / tuteur est requis" })
  parentName!: string;

  @ApiProperty({ example: "+237691234567" })
  @IsString()
  @IsNotEmpty({ message: "Le téléphone du parent / tuteur est requis" })
  parentPhone!: string;

  @ApiPropertyOptional({ example: "parent@example.com" })
  @IsOptional()
  @IsEmail(undefined, { message: "Adresse email invalide" })
  parentEmail?: string;

  @ApiPropertyOptional({ description: "École d'origine de l'élève" })
  @IsOptional()
  @IsString()
  previousSchool?: string;

  @ApiPropertyOptional({
    description:
      "Champ honeypot anti-bot : doit rester vide. S'il est rempli, la soumission est silencieusement ignorée.",
  })
  @IsOptional()
  @IsString()
  website?: string;
}
