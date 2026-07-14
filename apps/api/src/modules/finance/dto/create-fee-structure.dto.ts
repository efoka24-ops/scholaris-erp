import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { Type } from "class-transformer";
import { ArrayMinSize, IsArray, IsNotEmpty, IsNumber, IsOptional, IsPositive, IsString, IsUUID, ValidateNested } from "class-validator";
import { CreateFeeInstallmentDto } from "./create-fee-installment.dto";

export class CreateFeeStructureDto {
  @ApiProperty({ example: "Scolarité 6ème 2026-2027" })
  @IsString()
  @IsNotEmpty({ message: "Le nom de la grille tarifaire est requis" })
  name!: string;

  @ApiPropertyOptional({ description: "Niveau ciblé — omis ou null = s'applique à tous les niveaux" })
  @IsOptional()
  @IsUUID(undefined, { message: "Le niveau indiqué est invalide" })
  levelId?: string;

  @ApiProperty()
  @IsUUID(undefined, { message: "L'année académique est requise" })
  academicYearId!: string;

  @ApiProperty({ example: 150000 })
  @IsNumber()
  @IsPositive({ message: "Le montant total doit être positif" })
  totalAmount!: number;

  @ApiProperty({ type: [CreateFeeInstallmentDto] })
  @IsArray()
  @ArrayMinSize(1, { message: "Au moins une tranche est requise" })
  @ValidateNested({ each: true })
  @Type(() => CreateFeeInstallmentDto)
  installments!: CreateFeeInstallmentDto[];
}
