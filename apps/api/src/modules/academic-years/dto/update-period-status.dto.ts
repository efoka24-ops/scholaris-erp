import { ApiProperty } from "@nestjs/swagger";
import { GradingStatus } from "@scholaris/prisma";
import { IsEnum } from "class-validator";

export class UpdatePeriodStatusDto {
  @ApiProperty({ enum: GradingStatus, example: GradingStatus.OPEN })
  @IsEnum(GradingStatus, { message: "Le statut doit être CLOSED, OPEN ou LOCKED" })
  gradingStatus!: GradingStatus;
}
