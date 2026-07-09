import { ApiProperty } from "@nestjs/swagger";
import { Channel } from "@scholaris/prisma";
import { IsEnum, IsIn, IsObject, IsOptional, IsString, IsUUID } from "class-validator";

export class SendMessageDto {
  @ApiProperty({ required: false, description: "Template à rendre ; omis pour un message ad-hoc" })
  @IsOptional()
  @IsUUID()
  templateId?: string;

  @ApiProperty({ enum: Channel, required: false, description: "Requis pour un message ad-hoc (sans templateId)" })
  @IsOptional()
  @IsEnum(Channel, { message: "Canal invalide" })
  channel?: Channel;

  @ApiProperty()
  @IsUUID(undefined, { message: "Destinataire invalide" })
  recipientUserId!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  subject?: string;

  @ApiProperty({ required: false, description: "Requis pour un message ad-hoc (sans templateId)" })
  @IsOptional()
  @IsString()
  body?: string;

  @ApiProperty({ enum: ["fr", "en"], default: "fr", required: false })
  @IsOptional()
  @IsIn(["fr", "en"])
  locale?: "fr" | "en";

  @ApiProperty({ required: false, description: "Variables de substitution {clé} du template, ex: { nom_eleve: 'Awa' }" })
  @IsOptional()
  @IsObject()
  variables?: Record<string, string>;
}
