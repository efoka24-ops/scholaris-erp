import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { AdmissionStatus } from "@scholaris/prisma";
import { Type } from "class-transformer";
import { IsIn, IsInt, IsNumber, IsOptional, Min } from "class-validator";

export const DECISION_STATUSES = [
  AdmissionStatus.ACCEPTED,
  AdmissionStatus.REJECTED,
  AdmissionStatus.WAITLISTED,
] as const;

export class AdmissionDecisionDto {
  @ApiProperty({ enum: DECISION_STATUSES })
  @IsIn(DECISION_STATUSES, { message: "La décision doit être ACCEPTED, REJECTED ou WAITLISTED" })
  status!: (typeof DECISION_STATUSES)[number];

  @ApiPropertyOptional({ example: 14.25 })
  @IsOptional()
  @IsNumber()
  score?: number;

  @ApiPropertyOptional({ example: 12 })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  rank?: number;
}
