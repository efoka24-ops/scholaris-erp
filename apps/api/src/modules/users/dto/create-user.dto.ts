import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { UserStatus } from "@scholaris/prisma";
import {
  IsEmail,
  IsString,
  MinLength,
  IsOptional,
  IsEnum,
  IsArray,
  IsBoolean,
} from "class-validator";

export class CreateUserDto {
  @ApiProperty({ example: "john.doe@example.com" })
  @IsEmail()
  email!: string;

  @ApiProperty({ example: "SecureP@ssw0rd" })
  @IsString()
  @MinLength(8)
  password!: string;

  @ApiProperty({ example: "John" })
  @IsString()
  firstName!: string;

  @ApiProperty({ example: "Doe" })
  @IsString()
  lastName!: string;

  @ApiPropertyOptional({ example: "+237600000000" })
  @IsOptional()
  @IsString()
  phone?: string;

  @ApiPropertyOptional({ example: "https://example.com/avatar.jpg" })
  @IsOptional()
  @IsString()
  avatarUrl?: string;

  @ApiPropertyOptional({ enum: UserStatus, example: UserStatus.ACTIVE })
  @IsOptional()
  @IsEnum(UserStatus)
  status?: UserStatus;

  @ApiPropertyOptional({ example: false })
  @IsOptional()
  @IsBoolean()
  mfaEnabled?: boolean;

  @ApiPropertyOptional({ 
    example: ["role-uuid-1", "role-uuid-2"],
    description: "Array of role IDs to assign to the user"
  })
  @IsOptional()
  @IsArray()
  @IsString({ each: true })
  roleIds?: string[];
}
