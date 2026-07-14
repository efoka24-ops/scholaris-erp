import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsEmail, IsNotEmpty, IsOptional, IsString } from "class-validator";

export class LoginDto {
  @ApiProperty({ example: "admin@scholaris.dev" })
  @IsEmail({}, { message: "Adresse email invalide" })
  email!: string;

  @ApiProperty({ example: "ChangeMe123!" })
  @IsString()
  @IsNotEmpty({ message: "Le mot de passe est requis" })
  password!: string;

  @ApiPropertyOptional({ example: "123456", description: "Code TOTP requis si le MFA est activé sur le compte" })
  @IsOptional()
  @IsString()
  mfaCode?: string;
}
