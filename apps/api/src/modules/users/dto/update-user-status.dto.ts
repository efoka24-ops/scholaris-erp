import { ApiProperty } from "@nestjs/swagger";
import { UserStatus } from "@scholaris/prisma";
import { IsEnum } from "class-validator";

export class UpdateUserStatusDto {
  @ApiProperty({ enum: UserStatus, example: UserStatus.SUSPENDED })
  @IsEnum(UserStatus, { message: "Statut invalide (ACTIVE, INACTIVE ou SUSPENDED)" })
  status!: UserStatus;
}
