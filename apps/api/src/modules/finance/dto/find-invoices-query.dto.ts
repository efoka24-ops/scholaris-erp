import { ApiPropertyOptional } from "@nestjs/swagger";
import { InvoiceStatus } from "@scholaris/prisma";
import { Type } from "class-transformer";
import { IsEnum, IsInt, IsOptional, IsUUID, Min } from "class-validator";

export class FindInvoicesQueryDto {
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

  @ApiPropertyOptional({ description: "Filtre par classe (via l'inscription rattachée à la facture)" })
  @IsOptional()
  @IsUUID()
  classroomId?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID()
  academicYearId?: string;

  @ApiPropertyOptional({ enum: InvoiceStatus })
  @IsOptional()
  @IsEnum(InvoiceStatus)
  status?: InvoiceStatus;
}
