import { Body, Controller, Delete, Get, Param, Patch, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { DepartmentsService } from "./departments.service";
import { CreateDepartmentDto } from "./dto/create-department.dto";
import { UpdateDepartmentDto } from "./dto/update-department.dto";

@ApiTags("structure/departments")
@ApiBearerAuth()
@Controller("departments")
export class DepartmentsController {
  constructor(private readonly departmentsService: DepartmentsService) {}

  @Get()
  @RequirePermissions("departments:read")
  findAll() {
    return this.departmentsService.findAll();
  }

  @Get(":id")
  @RequirePermissions("departments:read")
  findOne(@Param("id") id: string) {
    return this.departmentsService.findOne(id);
  }

  @Post()
  @RequirePermissions("departments:create")
  create(@Body() dto: CreateDepartmentDto, @CurrentUser() user: AuthenticatedUser) {
    return this.departmentsService.create(dto, user.tenantId);
  }

  @Patch(":id")
  @RequirePermissions("departments:update")
  update(@Param("id") id: string, @Body() dto: UpdateDepartmentDto) {
    return this.departmentsService.update(id, dto);
  }

  @Delete(":id")
  @RequirePermissions("departments:update")
  remove(@Param("id") id: string) {
    return this.departmentsService.remove(id);
  }
}
