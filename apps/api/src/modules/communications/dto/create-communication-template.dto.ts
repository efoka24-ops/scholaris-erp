import { ApiProperty } from "@nestjs/swagger";
import { Channel } from "@scholaris/prisma";
import { IsEnum, IsNotEmpty, IsOptional, IsString } from "class-validator";

export class CreateCommunicationTemplateDto {
  @ApiProperty({ example: "CONVOCATION" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ example: "Convocation à un entretien" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiProperty({ enum: Channel, example: Channel.EMAIL })
  @IsEnum(Channel, { message: "Canal invalide" })
  channel!: Channel;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  subjectFr?: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  subjectEn?: string;

  @ApiProperty({ example: "Bonjour {nom_eleve}, vous êtes convoqué(e) le {date_echeance}." })
  @IsString()
  @IsNotEmpty({ message: "Le corps (FR) est requis" })
  bodyFr!: string;

  @ApiProperty({ required: false })
  @IsOptional()
  @IsString()
  bodyEn?: string;
}
