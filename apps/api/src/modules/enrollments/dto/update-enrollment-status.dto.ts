import { ApiProperty } from "@nestjs/swagger";
import { EnrollmentStatus } from "@scholaris/prisma";
import { IsEnum } from "class-validator";

export class UpdateEnrollmentStatusDto {
  @ApiProperty({ enum: EnrollmentStatus })
  @IsEnum(EnrollmentStatus, { message: "Statut invalide (PENDING, ACTIVE ou CANCELLED)" })
  status!: EnrollmentStatus;
}
