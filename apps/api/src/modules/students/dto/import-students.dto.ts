import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsNotEmpty, IsOptional, IsString } from "class-validator";

/**
 * Le fichier Excel transite en base64 dans un corps JSON : le proxy générique
 * `/api/proxy/**` du frontend ne transporte que du JSON (pas de multipart).
 */
export class ImportStudentsDto {
  @ApiPropertyOptional({ example: "eleves-6eme-a.xlsx" })
  @IsOptional()
  @IsString()
  filename?: string;

  @ApiProperty({ description: "Contenu du fichier .xlsx encodé en base64" })
  @IsString()
  @IsNotEmpty({ message: "Le contenu du fichier est requis" })
  contentBase64!: string;
}
