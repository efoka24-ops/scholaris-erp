import { ApiPropertyOptional } from "@nestjs/swagger";
import { Type } from "class-transformer";
import { IsDateString, IsInt, IsOptional, IsString, IsUUID, Min } from "class-validator";

export class AuditLogsQueryDto {
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

  @ApiPropertyOptional({ description: "Filtrer par utilisateur" })
  @IsOptional()
  @IsUUID("all", { message: "L'identifiant utilisateur doit être un UUID" })
  userId?: string;

  @ApiPropertyOptional({ description: "Filtrer par action (create, update, delete...)" })
  @IsOptional()
  @IsString()
  action?: string;

  @ApiPropertyOptional({ description: "Filtrer par ressource (academic-years, tenants...)" })
  @IsOptional()
  @IsString()
  resource?: string;

  @ApiPropertyOptional({ description: "Date de début (ISO 8601)" })
  @IsOptional()
  @IsDateString({}, { message: "La date de début doit être au format ISO 8601" })
  dateFrom?: string;

  @ApiPropertyOptional({ description: "Date de fin (ISO 8601)" })
  @IsOptional()
  @IsDateString({}, { message: "La date de fin doit être au format ISO 8601" })
  dateTo?: string;
}
