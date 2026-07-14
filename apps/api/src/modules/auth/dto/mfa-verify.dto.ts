import { ApiProperty } from "@nestjs/swagger";
import { IsNotEmpty, IsString, Matches } from "class-validator";

export class MfaVerifyDto {
  @ApiProperty({ example: "123456", description: "Code TOTP à 6 chiffres généré par l'application d'authentification" })
  @IsString()
  @IsNotEmpty({ message: "Le code MFA est requis" })
  @Matches(/^\d{6}$/, { message: "Le code MFA doit contenir 6 chiffres" })
  code!: string;
}
