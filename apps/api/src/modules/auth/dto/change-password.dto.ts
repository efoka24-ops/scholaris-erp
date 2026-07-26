import { ApiProperty } from "@nestjs/swagger";
import { IsNotEmpty, IsString, MinLength } from "class-validator";

export class ChangePasswordDto {
  @ApiProperty({ description: "Mot de passe actuel" })
  @IsString()
  @IsNotEmpty({ message: "Le mot de passe actuel est requis" })
  currentPassword!: string;

  @ApiProperty({ description: "Nouveau mot de passe (min. 8 caractères)" })
  @IsString()
  @MinLength(8, { message: "Le nouveau mot de passe doit contenir au moins 8 caractères" })
  newPassword!: string;
}
