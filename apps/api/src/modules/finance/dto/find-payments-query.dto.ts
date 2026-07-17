import { ApiPropertyOptional } from "@nestjs/swagger";
import { PaymentMethod } from "@scholaris/prisma";
import { Type } from "class-transformer";
import { IsDateString, IsEnum, IsInt, IsOptional, IsUUID, Min } from "class-validator";

export class FindPaymentsQueryDto {
  @ApiPropertyOptional({ default: 1 })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  page?: number;

  @ApiPropertyOptional({ default: 20 })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  limit?: number;

  @ApiPropertyOptional({ description: "Filtre par élève" })
  @IsOptional()
  @IsUUID()
  studentId?: string;

  @ApiPropertyOptional({ description: "Filtre par facture" })
  @IsOptional()
  @IsUUID()
  invoiceId?: string;

  @ApiPropertyOptional({ enum: PaymentMethod })
  @IsOptional()
  @IsEnum(PaymentMethod)
  method?: PaymentMethod;

  @ApiPropertyOptional({ description: "Date de début (incluse), format ISO" })
  @IsOptional()
  @IsDateString()
  dateFrom?: string;

  @ApiPropertyOptional({ description: "Date de fin (incluse), format ISO" })
  @IsOptional()
  @IsDateString()
  dateTo?: string;
}
