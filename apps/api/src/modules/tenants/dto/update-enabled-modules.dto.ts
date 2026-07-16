import { ApiProperty } from "@nestjs/swagger";
import { IsArray, IsString } from "class-validator";

export class UpdateEnabledModulesDto {
  @ApiProperty({
    example: ["students", "finance", "grades", "communication"],
    description: "Liste des codes de modules activés pour cet établissement",
  })
  @IsArray()
  @IsString({ each: true })
  enabledModules!: string[];
}
