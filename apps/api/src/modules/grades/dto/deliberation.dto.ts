import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { Type } from "class-transformer";
import { ArrayMinSize, IsArray, IsIn, IsOptional, IsString, IsUUID, ValidateNested } from "class-validator";

export const DELIBERATION_DECISIONS = [
  "ADMIS",
  "AVERTISSEMENT",
  "BLAME",
  "EXCLUSION_TEMPORAIRE",
  "EXCLUSION_DEFINITIVE",
] as const;

export class DeliberationEntryDto {
  @ApiProperty()
  @IsUUID(undefined, { message: "L'élève est requis" })
  studentId!: string;

  @ApiPropertyOptional({ enum: DELIBERATION_DECISIONS })
  @IsOptional()
  @IsIn(DELIBERATION_DECISIONS, { message: "Décision de délibération invalide" })
  decision?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  observations?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  teacherComment?: string;
}

/** Overrides de délibération (décision/observations) appliqués aux PeriodResult déjà calculés. */
export class DeliberationDto {
  @ApiProperty({ type: [DeliberationEntryDto] })
  @IsArray()
  @ArrayMinSize(1, { message: "Au moins une décision est requise" })
  @ValidateNested({ each: true })
  @Type(() => DeliberationEntryDto)
  entries!: DeliberationEntryDto[];
}
