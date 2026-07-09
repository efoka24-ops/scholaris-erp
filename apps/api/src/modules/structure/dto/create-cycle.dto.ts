import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsInt, IsNotEmpty, IsOptional, IsString, Min } from "class-validator";

export class CreateCycleDto {
  @ApiProperty({ example: "SEC1" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ example: "Premier cycle" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiPropertyOptional({ description: "Auto-incrémenté si omis" })
  @IsOptional()
  @IsInt()
  @Min(0)
  order?: number;
}
