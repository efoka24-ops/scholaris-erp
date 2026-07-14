import { Body, Controller, Get, Param, Post, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiQuery, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { TeachingUnitsService } from "./teaching-units.service";
import { CreateTeachingUnitDto } from "./dto/create-teaching-unit.dto";

@ApiTags("teaching-units")
@ApiBearerAuth()
@Controller("teaching-units")
export class TeachingUnitsController {
  constructor(private readonly teachingUnitsService: TeachingUnitsService) {}

  @Get()
  @RequirePermissions("teaching-units:read")
  @ApiQuery({ name: "departmentId", required: false })
  @ApiQuery({ name: "semester", required: false })
  findAll(@Query("departmentId") departmentId?: string, @Query("semester") semester?: string) {
    return this.teachingUnitsService.findAll({
      departmentId,
      semester: semester ? Number(semester) : undefined,
    });
  }

  @Get(":id")
  @RequirePermissions("teaching-units:read")
  findOne(@Param("id") id: string) {
    return this.teachingUnitsService.findOne(id);
  }

  @Post()
  @RequirePermissions("teaching-units:create")
  create(@Body() dto: CreateTeachingUnitDto, @CurrentUser() user: AuthenticatedUser) {
    return this.teachingUnitsService.create(dto, user.tenantId);
  }
}
