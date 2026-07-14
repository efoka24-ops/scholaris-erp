import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsInt, IsNotEmpty, IsNumber, IsOptional, IsPositive, IsString, IsUUID, Min } from "class-validator";

export class CreateCourseElementDto {
  @ApiProperty({ example: "EC-INF-101-1" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ example: "Programmation en C" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiProperty({ example: 3 })
  @IsInt({ message: "Les crédits doivent être un entier" })
  @IsPositive({ message: "Les crédits doivent être strictement positifs" })
  credits!: number;

  @ApiPropertyOptional({ example: 20, default: 0 })
  @IsOptional()
  @IsInt()
  @Min(0, { message: "Les heures de CM ne peuvent pas être négatives" })
  hoursCm?: number;

  @ApiPropertyOptional({ example: 15, default: 0 })
  @IsOptional()
  @IsInt()
  @Min(0, { message: "Les heures de TD ne peuvent pas être négatives" })
  hoursTd?: number;

  @ApiPropertyOptional({ example: 10, default: 0 })
  @IsOptional()
  @IsInt()
  @Min(0, { message: "Les heures de TP ne peuvent pas être négatives" })
  hoursTp?: number;

  @ApiPropertyOptional({ example: 1, default: 1 })
  @IsOptional()
  @IsNumber({}, { message: "Le coefficient doit être un nombre" })
  @IsPositive({ message: "Le coefficient doit être strictement positif" })
  coefficient?: number;

  @ApiProperty()
  @IsUUID(undefined, { message: "L'unité d'enseignement est requise" })
  teachingUnitId!: string;
}
