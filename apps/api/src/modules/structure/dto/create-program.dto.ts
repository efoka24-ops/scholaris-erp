import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsNotEmpty, IsOptional, IsString, IsUUID } from "class-validator";

export class CreateProgramDto {
  @ApiProperty({ example: "GI" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ example: "Génie Informatique" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiProperty()
  @IsUUID(undefined, { message: "Le cycle est requis" })
  cycleId!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID()
  departmentId?: string;
}
