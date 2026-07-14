import { ApiProperty } from "@nestjs/swagger";
import { IsDateString, IsNotEmpty, IsString } from "class-validator";

export class CreateAcademicYearDto {
  @ApiProperty({ example: "2026-2027" })
  @IsString()
  @IsNotEmpty({ message: "Le libellé est requis" })
  label!: string;

  @ApiProperty({ example: "2026-09-01" })
  @IsDateString({}, { message: "La date de début doit être au format ISO 8601" })
  startDate!: string;

  @ApiProperty({ example: "2027-06-30" })
  @IsDateString({}, { message: "La date de fin doit être au format ISO 8601" })
  endDate!: string;
}
