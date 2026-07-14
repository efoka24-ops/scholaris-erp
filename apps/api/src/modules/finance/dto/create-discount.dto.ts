import { ApiPropertyOptional, ApiProperty } from "@nestjs/swagger";
import { DiscountType } from "@scholaris/prisma";
import { IsEnum, IsNumber, IsOptional, IsPositive, IsString, IsUUID } from "class-validator";

export class CreateDiscountDto {
  @ApiPropertyOptional({ description: "Élève bénéficiaire — requis si invoiceId n'est pas fourni" })
  @IsOptional()
  @IsUUID()
  studentId?: string;

  @ApiPropertyOptional({ description: "Facture ciblée — si fournie, la réduction est appliquée immédiatement" })
  @IsOptional()
  @IsUUID()
  invoiceId?: string;

  @ApiProperty({ enum: DiscountType })
  @IsEnum(DiscountType, { message: "Le type de réduction est invalide" })
  type!: DiscountType;

  @ApiProperty({ example: 25, description: "Pourcentage (0-100) ou montant fixe selon le type" })
  @IsNumber()
  @IsPositive({ message: "La valeur de la réduction doit être positive" })
  value!: number;

  @ApiPropertyOptional({ example: "Bourse d'excellence" })
  @IsOptional()
  @IsString()
  reason?: string;
}
