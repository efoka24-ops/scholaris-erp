import { Body, Controller, Delete, Get, Param, Patch, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { ProgramsService } from "./programs.service";
import { CreateProgramDto } from "./dto/create-program.dto";
import { UpdateProgramDto } from "./dto/update-program.dto";

@ApiTags("structure/programs")
@ApiBearerAuth()
@Controller("programs")
export class ProgramsController {
  constructor(private readonly programsService: ProgramsService) {}

  @Get()
  @RequirePermissions("programs:read")
  findAll() {
    return this.programsService.findAll();
  }

  @Get(":id")
  @RequirePermissions("programs:read")
  findOne(@Param("id") id: string) {
    return this.programsService.findOne(id);
  }

  @Post()
  @RequirePermissions("programs:create")
  create(@Body() dto: CreateProgramDto, @CurrentUser() user: AuthenticatedUser) {
    return this.programsService.create(dto, user.tenantId);
  }

  @Patch(":id")
  @RequirePermissions("programs:update")
  update(@Param("id") id: string, @Body() dto: UpdateProgramDto) {
    return this.programsService.update(id, dto);
  }

  @Delete(":id")
  @RequirePermissions("programs:update")
  remove(@Param("id") id: string) {
    return this.programsService.remove(id);
  }
}
