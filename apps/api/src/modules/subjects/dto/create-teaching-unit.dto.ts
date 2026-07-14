import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsBoolean, IsInt, IsNotEmpty, IsOptional, IsPositive, IsString, IsUUID, Min } from "class-validator";

export class CreateTeachingUnitDto {
  @ApiProperty({ example: "UE-INF-101" })
  @IsString()
  @IsNotEmpty({ message: "Le code est requis" })
  code!: string;

  @ApiProperty({ example: "Algorithmique et programmation" })
  @IsString()
  @IsNotEmpty({ message: "Le nom est requis" })
  name!: string;

  @ApiProperty({ example: 6 })
  @IsInt({ message: "Les crédits doivent être un entier" })
  @IsPositive({ message: "Les crédits doivent être strictement positifs" })
  credits!: number;

  @ApiProperty({ example: 1 })
  @IsInt({ message: "Le semestre doit être un entier" })
  @Min(1, { message: "Le semestre doit être supérieur ou égal à 1" })
  semester!: number;

  @ApiPropertyOptional({ default: false })
  @IsOptional()
  @IsBoolean()
  isFundamental?: boolean;

  @ApiProperty()
  @IsUUID(undefined, { message: "Le département est requis" })
  departmentId!: string;
}
