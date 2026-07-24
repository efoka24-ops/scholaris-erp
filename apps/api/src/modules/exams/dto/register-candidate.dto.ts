import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsArray, IsBoolean, IsOptional, IsString, IsNotEmpty, ArrayNotEmpty } from "class-validator";

/** Inscription d'un candidat unique à un examen. */
export class RegisterCandidateDto {
  @ApiProperty({ description: "ID de l'élève à inscrire" })
  @IsString()
  @IsNotEmpty()
  studentId!: string;

  @ApiPropertyOptional({ description: "Série (BAC/Probatoire) : A4, C, D, TI…" })
  @IsOptional()
  @IsString()
  series?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  centerCode?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  centerName?: string;

  @ApiPropertyOptional({ description: "Frais d'examen déjà payés" })
  @IsOptional()
  @IsBoolean()
  feePaid?: boolean;
}

/** Inscription batch : une liste d'élèves (ex: toute une classe / un niveau). */
export class RegisterBatchDto {
  @ApiProperty({ type: [String], description: "IDs des élèves à inscrire" })
  @IsArray()
  @ArrayNotEmpty({ message: "La liste des élèves ne peut être vide" })
  @IsString({ each: true })
  studentIds!: string[];

  @ApiPropertyOptional({ description: "Série commune (BAC/Probatoire)" })
  @IsOptional()
  @IsString()
  series?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  centerCode?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  centerName?: string;
}
