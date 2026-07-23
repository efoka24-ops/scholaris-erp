import { ApiPropertyOptional } from "@nestjs/swagger";
import { IsOptional, IsString } from "class-validator";

export class RejectEstablishmentRequestDto {
  @ApiPropertyOptional({ description: "Motif du rejet (communiqué au demandeur)" })
  @IsOptional()
  @IsString()
  reason?: string;
}
