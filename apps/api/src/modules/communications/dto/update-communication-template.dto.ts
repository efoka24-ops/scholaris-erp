import { ApiProperty } from "@nestjs/swagger";
import { Channel } from "@scholaris/prisma";
import { IsEnum, IsOptional, IsString } from "class-validator";

export class UpdateCommunicationTemplateDto {
  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  code?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  name?: string;

  @ApiProperty({ enum: Channel, required: false })
  @IsOptional()
  @IsEnum(Channel, { message: "Canal invalide" })
  channel?: Channel;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  subjectFr?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  subjectEn?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  bodyFr?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  bodyEn?: string;
}
