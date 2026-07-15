import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsString, IsOptional, IsEnum } from "class-validator";

export enum BulletinStatus {
  DRAFT = "draft",
  PUBLISHED = "published",
  SENT = "sent",
}

export class GenerateBulletinDto {
  @ApiProperty({ example: "classroom-uuid" })
  @IsString()
  classroomId!: string;

  @ApiProperty({ example: "period-uuid" })
  @IsString()
  periodId!: string;

  @ApiPropertyOptional({ description: "Send via email/SMS after generation" })
  @IsOptional()
  autoSend?: boolean;
}

export class GenerateSingleBulletinDto {
  @ApiProperty({ example: "student-uuid" })
  @IsString()
  studentId!: string;

  @ApiProperty({ example: "period-uuid" })
  @IsString()
  periodId!: string;
}

export class SendBulletinsDto {
  @ApiProperty({ example: "classroom-uuid" })
  @IsString()
  classroomId!: string;

  @ApiProperty({ example: "period-uuid" })
  @IsString()
  periodId!: string;

  @ApiPropertyOptional({ enum: ["email", "sms", "whatsapp"], default: "email" })
  @IsOptional()
  @IsEnum(["email", "sms", "whatsapp"])
  channel?: string;
}
