import { ApiProperty } from "@nestjs/swagger";
import { IsUUID } from "class-validator";

export class CalculateAnnualQueryDto {
  @ApiProperty()
  @IsUUID(undefined, { message: "L'année académique est requise" })
  academicYearId!: string;
}
