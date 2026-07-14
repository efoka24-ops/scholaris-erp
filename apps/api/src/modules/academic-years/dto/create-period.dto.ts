import { ApiProperty } from "@nestjs/swagger";
import { PeriodType } from "@scholaris/prisma";
import { Type } from "class-transformer";
import { IsDateString, IsEnum, IsInt, IsUUID, Min } from "class-validator";

export class CreatePeriodDto {
  @ApiProperty({ description: "Année académique de rattachement" })
  @IsUUID("all", { message: "L'année académique doit être un UUID valide" })
  academicYearId!: string;

  @ApiProperty({ enum: PeriodType, example: PeriodType.SEQUENCE })
  @IsEnum(PeriodType, { message: "Le type doit être SEQUENCE, TRIMESTER ou SEMESTER" })
  type!: PeriodType;

  @ApiProperty({ example: 1 })
  @Type(() => Number)
  @IsInt({ message: "Le numéro doit être un entier" })
  @Min(1, { message: "Le numéro doit être supérieur ou égal à 1" })
  number!: number;

  @ApiProperty({ example: "2026-09-01" })
  @IsDateString({}, { message: "La date de début doit être au format ISO 8601" })
  startDate!: string;

  @ApiProperty({ example: "2026-10-31" })
  @IsDateString({}, { message: "La date de fin doit être au format ISO 8601" })
  endDate!: string;
}
