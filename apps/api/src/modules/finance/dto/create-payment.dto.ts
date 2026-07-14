import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { PaymentMethod } from "@scholaris/prisma";
import { IsDateString, IsEnum, IsNumber, IsOptional, IsPositive, IsString, IsUUID } from "class-validator";

export class CreatePaymentDto {
  @ApiProperty()
  @IsUUID(undefined, { message: "La facture est requise" })
  invoiceId!: string;

  @ApiProperty({ example: 25000 })
  @IsNumber()
  @IsPositive({ message: "Le montant payé doit être positif" })
  amount!: number;

  @ApiProperty({ enum: PaymentMethod })
  @IsEnum(PaymentMethod, { message: "Le mode de paiement est invalide" })
  method!: PaymentMethod;

  @ApiPropertyOptional({ description: "Référence externe (n° transaction Mobile Money, n° chèque...)" })
  @IsOptional()
  @IsString()
  reference?: string;

  @ApiPropertyOptional({ description: "Date du paiement — par défaut maintenant" })
  @IsOptional()
  @IsDateString({}, { message: "La date de paiement est invalide" })
  paidAt?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  notes?: string;
}
