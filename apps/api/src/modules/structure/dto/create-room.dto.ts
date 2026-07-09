import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { RoomType } from "@scholaris/prisma";
import { IsArray, IsEnum, IsInt, IsNotEmpty, IsOptional, IsPositive, IsString } from "class-validator";

export class CreateRoomDto {
  @ApiProperty({ example: "LAB-INFO-1" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ example: "Laboratoire Informatique 1" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiProperty({ enum: RoomType })
  @IsEnum(RoomType)
  type!: RoomType;

  @ApiPropertyOptional()
  @IsOptional()
  @IsInt()
  @IsPositive()
  capacity?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  building?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  floor?: string;

  @ApiPropertyOptional({ type: [String] })
  @IsOptional()
  @IsArray()
  @IsString({ each: true })
  equipment?: string[];
}
