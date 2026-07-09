import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { Section } from "@scholaris/prisma";
import { IsEnum, IsInt, IsNotEmpty, IsOptional, IsPositive, IsString, IsUUID } from "class-validator";

export class CreateClassRoomDto {
  @ApiProperty({ example: "6EME-A" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ example: "6ème A" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiProperty({ example: 60 })
  @IsInt()
  @IsPositive({ message: "La capacité doit être positive" })
  capacity!: number;

  @ApiProperty()
  @IsUUID(undefined, { message: "Le niveau est requis" })
  levelId!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID()
  mainTeacherId?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID()
  roomId?: string;

  @ApiProperty({ enum: Section })
  @IsEnum(Section)
  section!: Section;
}
