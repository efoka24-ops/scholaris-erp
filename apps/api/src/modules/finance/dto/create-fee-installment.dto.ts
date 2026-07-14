import { ApiProperty } from "@nestjs/swagger";
import { IsDateString, IsInt, IsNotEmpty, IsNumber, IsPositive, IsString, Min } from "class-validator";

export class CreateFeeInstallmentDto {
  @ApiProperty({ example: "1ère tranche" })
  @IsString()
  @IsNotEmpty({ message: "Le libellé de la tranche est requis" })
  label!: string;

  @ApiProperty({ example: 50000 })
  @IsNumber()
  @IsPositive({ message: "Le montant de la tranche doit être positif" })
  amount!: number;

  @ApiProperty({ example: "2026-10-15" })
  @IsDateString({}, { message: "La date d'échéance est invalide" })
  dueDate!: string;

  @ApiProperty({ example: 1 })
  @IsInt()
  @Min(1)
  order!: number;
}
