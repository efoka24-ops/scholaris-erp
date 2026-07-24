import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { Type } from "class-transformer";
import {
  IsArray,
  IsBoolean,
  IsNotEmpty,
  IsNumber,
  IsOptional,
  IsString,
  Min,
  ValidateNested,
  ArrayNotEmpty,
} from "class-validator";

/** Une note d'une matière pour un candidat. */
export class ExamResultLineDto {
  @ApiProperty({ description: "N° d'inscription du candidat" })
  @IsString()
  @IsNotEmpty()
  registrationNumber!: string;

  @ApiProperty({ example: "Mathématiques" })
  @IsString()
  @IsNotEmpty()
  subject!: string;

  @ApiProperty({ example: 8, description: "Coefficient de la matière" })
  @IsNumber()
  @Min(0)
  coefficient!: number;

  @ApiPropertyOptional({ example: 14.5, description: "Note /20 (absent si non fournie)" })
  @IsOptional()
  @IsNumber()
  @Min(0)
  mark?: number;

  @ApiPropertyOptional({ description: "Candidat absent → note 0" })
  @IsOptional()
  @IsBoolean()
  isAbsent?: boolean;
}

/** Import des résultats d'un examen (remplace les résultats existants). */
export class SubmitResultsDto {
  @ApiProperty({ type: [ExamResultLineDto] })
  @IsArray()
  @ArrayNotEmpty({ message: "Aucune note fournie" })
  @ValidateNested({ each: true })
  @Type(() => ExamResultLineDto)
  results!: ExamResultLineDto[];
}
