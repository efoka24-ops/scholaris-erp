import { ApiPropertyOptional } from "@nestjs/swagger";
import { IsArray, IsNotEmpty, IsOptional, IsString } from "class-validator";

export class UpdateRoleDto {
  @ApiPropertyOptional({ example: "Censeur" })
  @IsOptional()
  @IsString()
  @IsNotEmpty({ message: "Le nom du rôle ne peut pas être vide" })
  name?: string;

  @ApiPropertyOptional({ example: "Gestion pédagogique et discipline" })
  @IsOptional()
  @IsString()
  description?: string;

  @ApiPropertyOptional({
    example: ["permission-uuid-1", "permission-uuid-2"],
    description: "Remplace la liste complète des permissions du rôle",
  })
  @IsOptional()
  @IsArray()
  @IsString({ each: true })
  permissionIds?: string[];
}
