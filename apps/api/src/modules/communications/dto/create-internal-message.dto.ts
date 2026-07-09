import { ApiProperty } from "@nestjs/swagger";
import { IsNotEmpty, IsString, IsUUID } from "class-validator";

export class CreateInternalMessageDto {
  @ApiProperty()
  @IsUUID(undefined, { message: "Destinataire invalide" })
  recipientUserId!: string;

  @ApiProperty()
  @IsString()
  @IsNotEmpty({ message: "Le message ne peut pas être vide" })
  body!: string;
}
