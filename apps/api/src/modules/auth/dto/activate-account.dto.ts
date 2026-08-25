import { ApiProperty } from "@nestjs/swagger";
import { IsNotEmpty, IsString } from "class-validator";

export class ActivateAccountDto {
  @ApiProperty({ description: "Token d'activation reçu par email (lien /activate?token=...)" })
  @IsString()
  @IsNotEmpty({ message: "Le token d'activation est requis" })
  token!: string;
}
