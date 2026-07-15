import { ApiProperty } from "@nestjs/swagger";
import { IsArray, IsString } from "class-validator";

export class AssignRolesDto {
  @ApiProperty({
    example: ["role-uuid-1", "role-uuid-2"],
    description: "Array of role IDs to assign to the user",
  })
  @IsArray()
  @IsString({ each: true })
  roleIds!: string[];
}
