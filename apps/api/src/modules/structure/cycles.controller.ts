import { Body, Controller, Delete, Get, Param, Patch, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CyclesService } from "./cycles.service";
import { CreateCycleDto } from "./dto/create-cycle.dto";
import { UpdateCycleDto } from "./dto/update-cycle.dto";

@ApiTags("structure/cycles")
@ApiBearerAuth()
@Controller("cycles")
export class CyclesController {
  constructor(private readonly cyclesService: CyclesService) {}

  @Get()
  @RequirePermissions("cycles:read")
  findAll() {
    return this.cyclesService.findAll();
  }

  @Get(":id")
  @RequirePermissions("cycles:read")
  findOne(@Param("id") id: string) {
    return this.cyclesService.findOne(id);
  }

  @Post()
  @RequirePermissions("cycles:create")
  create(@Body() dto: CreateCycleDto, @CurrentUser() user: AuthenticatedUser) {
    return this.cyclesService.create(dto, user.tenantId);
  }

  @Patch(":id")
  @RequirePermissions("cycles:create")
  update(@Param("id") id: string, @Body() dto: UpdateCycleDto) {
    return this.cyclesService.update(id, dto);
  }

  @Delete(":id")
  @RequirePermissions("cycles:create")
  remove(@Param("id") id: string) {
    return this.cyclesService.remove(id);
  }
}
