import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsBoolean, IsNumber, IsOptional, IsString, IsUUID, Min } from "class-validator";

/** Une note au sein d'une saisie collective (POST /grades/batch). */
export class GradeEntryDto {
  @ApiProperty()
  @IsUUID(undefined, { message: "L'élève est requis" })
  studentId!: string;

  @ApiPropertyOptional({ description: "Note obtenue (absente si isAbsent=true)" })
  @IsOptional()
  @IsNumber({}, { message: "La note doit être un nombre" })
  @Min(0, { message: "La note ne peut pas être négative" })
  value?: number;

  @ApiPropertyOptional({ default: false })
  @IsOptional()
  @IsBoolean()
  isAbsent?: boolean;

  @ApiPropertyOptional({ default: false })
  @IsOptional()
  @IsBoolean()
  isJustified?: boolean;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  comment?: string;
}
