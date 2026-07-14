import { ApiPropertyOptional } from "@nestjs/swagger";
import { IsBoolean, IsNumber, IsOptional, IsPositive, IsString, Min } from "class-validator";

export class UpdateGradeDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsNumber({}, { message: "La note doit être un nombre" })
  @Min(0, { message: "La note ne peut pas être négative" })
  value?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsNumber({}, { message: "Le barème doit être un nombre" })
  @IsPositive({ message: "Le barème doit être strictement positif" })
  maxValue?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsNumber({}, { message: "Le poids doit être un nombre" })
  @IsPositive({ message: "Le poids doit être strictement positif" })
  weight?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsBoolean()
  isAbsent?: boolean;

  @ApiPropertyOptional()
  @IsOptional()
  @IsBoolean()
  isJustified?: boolean;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  comment?: string;
}
