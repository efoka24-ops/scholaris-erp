import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsBoolean, IsNotEmpty, IsOptional, IsString } from "class-validator";

/**
 * Le fichier Excel transite en base64 dans un corps JSON : le proxy générique
 * Next.js `/api/proxy/**` ne transporte que du JSON (pas de multipart), et un
 * référentiel de matières reste petit (quelques dizaines de lignes).
 */
export class ImportSubjectsDto {
  @ApiProperty({ description: "Contenu du fichier .xlsx encodé en base64" })
  @IsString()
  @IsNotEmpty({ message: "Le fichier est requis" })
  fileBase64!: string;

  @ApiPropertyOptional({ description: "Nom du fichier d'origine (informatif)" })
  @IsOptional()
  @IsString()
  filename?: string;

  @ApiPropertyOptional({
    default: false,
    description: "true = prévisualisation seule : parse et valide sans rien créer",
  })
  @IsOptional()
  @IsBoolean()
  dryRun?: boolean;
}
