import { ApiProperty } from "@nestjs/swagger";
import { Channel } from "@scholaris/prisma";
import { IsEnum, IsOptional } from "class-validator";

export class ChannelPreferenceDto {
  @ApiProperty({ enum: Channel, example: Channel.EMAIL })
  @IsEnum(Channel, { message: "Canal invalide" })
  preferredChannel!: Channel;

  @ApiProperty({ enum: Channel, required: false })
  @IsOptional()
  @IsEnum(Channel, { message: "Canal invalide" })
  fallbackChannel?: Channel;
}
