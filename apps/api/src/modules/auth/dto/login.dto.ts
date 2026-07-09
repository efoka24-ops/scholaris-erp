import { ApiProperty } from "@nestjs/swagger";
import { IsEmail, IsNotEmpty, IsString } from "class-validator";

export class LoginDto {
  @ApiProperty({ example: "admin@scholaris.dev" })
  @IsEmail({}, { message: "Adresse email invalide" })
  email!: string;

  @ApiProperty({ example: "ChangeMe123!" })
  @IsString()
  @IsNotEmpty({ message: "Le mot de passe est requis" })
  password!: string;
}
