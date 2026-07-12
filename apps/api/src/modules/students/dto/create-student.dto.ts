import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { Gender, StudentStatus } from "@scholaris/prisma";
import { Type } from "class-transformer";
import {
  IsArray,
  IsBoolean,
  IsDateString,
  IsEnum,
  IsNotEmpty,
  IsOptional,
  IsString,
  ValidateNested,
} from "class-validator";
import { CreateParentDto } from "./create-parent.dto";

export class CreateStudentDto {
  @ApiProperty({ example: "Aminata" })
  @IsString()
  @IsNotEmpty({ message: "Le prénom est requis" })
  firstName!: string;

  @ApiProperty({ example: "Ngo Bassa" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  lastName!: string;

  @ApiProperty({ example: "2013-04-21" })
  @IsDateString({}, { message: "La date de naissance est invalide" })
  dateOfBirth!: string;

  @ApiPropertyOptional({ example: "Douala" })
  @IsOptional()
  @IsString()
  placeOfBirth?: string;

  @ApiProperty({ enum: Gender })
  @IsEnum(Gender, { message: "Le sexe est requis (MALE ou FEMALE)" })
  gender!: Gender;

  @ApiPropertyOptional({ example: "Camerounaise", default: "Camerounaise" })
  @IsOptional()
  @IsString()
  nationality?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  photoUrl?: string;

  @ApiPropertyOptional({ example: "O+" })
  @IsOptional()
  @IsString()
  bloodGroup?: string;

  @ApiPropertyOptional({ example: "Arachides" })
  @IsOptional()
  @IsString()
  allergies?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  handicap?: string;

  @ApiPropertyOptional({ example: "+237699000000 (oncle)" })
  @IsOptional()
  @IsString()
  emergencyContact?: string;

  @ApiPropertyOptional({ enum: StudentStatus, default: StudentStatus.ACTIVE })
  @IsOptional()
  @IsEnum(StudentStatus)
  status?: StudentStatus;

  @ApiPropertyOptional({ type: [CreateParentDto] })
  @IsOptional()
  @IsArray()
  @ValidateNested({ each: true })
  @Type(() => CreateParentDto)
  parents?: CreateParentDto[];

  @ApiPropertyOptional({
    description: "Force la création malgré un doublon potentiel (nom + prénom + date de naissance)",
    default: false,
  })
  @IsOptional()
  @IsBoolean()
  force?: boolean;
}
