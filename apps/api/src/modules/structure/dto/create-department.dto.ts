import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsNotEmpty, IsOptional, IsString, IsUUID } from "class-validator";

export class CreateDepartmentDto {
  @ApiProperty({ example: "INFO" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ example: "Informatique" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID()
  headTeacherId?: string;
}
