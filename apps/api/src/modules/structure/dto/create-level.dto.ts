import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsInt, IsNotEmpty, IsOptional, IsString, IsUUID, Min } from "class-validator";

export class CreateLevelDto {
  @ApiProperty({ example: "6EME" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ example: "6ème" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiPropertyOptional({ description: "Auto-incrémenté si omis" })
  @IsOptional()
  @IsInt()
  @Min(0)
  order?: number;

  @ApiProperty()
  @IsUUID(undefined, { message: "Le cycle est requis" })
  cycleId!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID()
  programId?: string;
}
