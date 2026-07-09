import { Body, Controller, Delete, Get, Param, Patch, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { LevelsService } from "./levels.service";
import { CreateLevelDto } from "./dto/create-level.dto";
import { UpdateLevelDto } from "./dto/update-level.dto";

@ApiTags("structure/levels")
@ApiBearerAuth()
@Controller("levels")
export class LevelsController {
  constructor(private readonly levelsService: LevelsService) {}

  @Get()
  @RequirePermissions("levels:read")
  findAll() {
    return this.levelsService.findAll();
  }

  @Get(":id")
  @RequirePermissions("levels:read")
  findOne(@Param("id") id: string) {
    return this.levelsService.findOne(id);
  }

  @Post()
  @RequirePermissions("levels:create")
  create(@Body() dto: CreateLevelDto, @CurrentUser() user: AuthenticatedUser) {
    return this.levelsService.create(dto, user.tenantId);
  }

  @Patch(":id")
  @RequirePermissions("levels:update")
  update(@Param("id") id: string, @Body() dto: UpdateLevelDto) {
    return this.levelsService.update(id, dto);
  }

  @Delete(":id")
  @RequirePermissions("levels:delete")
  remove(@Param("id") id: string) {
    return this.levelsService.remove(id);
  }
}
