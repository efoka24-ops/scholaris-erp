import { ApiProperty, ApiPropertyOptional } from "@nestjs/swagger";
import { IsArray, IsNotEmpty, IsOptional, IsString } from "class-validator";

export class CreateRoleDto {
  @ApiProperty({ example: "Censeur" })
  @IsString()
  @IsNotEmpty({ message: "Le nom du rôle est requis" })
  name!: string;

  @ApiPropertyOptional({ example: "Gestion pédagogique et discipline" })
  @IsOptional()
  @IsString()
  description?: string;

  @ApiPropertyOptional({
    example: ["permission-uuid-1", "permission-uuid-2"],
    description: "Identifiants des permissions à assigner au rôle",
  })
  @IsOptional()
  @IsArray()
  @IsString({ each: true })
  permissionIds?: string[];
}
