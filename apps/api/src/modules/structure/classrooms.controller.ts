import { Body, Controller, Get, Param, Patch, Post, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiQuery, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { ClassroomsService } from "./classrooms.service";
import { CreateClassRoomDto } from "./dto/create-classroom.dto";
import { UpdateClassRoomDto } from "./dto/update-classroom.dto";

@ApiTags("structure/classrooms")
@ApiBearerAuth()
@Controller("classrooms")
export class ClassroomsController {
  constructor(private readonly classroomsService: ClassroomsService) {}

  @Get()
  @RequirePermissions("classrooms:read")
  @ApiQuery({ name: "levelId", required: false })
  @ApiQuery({ name: "programId", required: false })
  findAll(@Query("levelId") levelId?: string, @Query("programId") programId?: string) {
    return this.classroomsService.findAll({ levelId, programId });
  }

  @Get(":id")
  @RequirePermissions("classrooms:read")
  findOne(@Param("id") id: string) {
    return this.classroomsService.findOne(id);
  }

  @Post()
  @RequirePermissions("classrooms:create")
  create(@Body() dto: CreateClassRoomDto, @CurrentUser() user: AuthenticatedUser) {
    return this.classroomsService.create(dto, user.tenantId);
  }

  @Patch(":id")
  @RequirePermissions("classrooms:update")
  update(@Param("id") id: string, @Body() dto: UpdateClassRoomDto) {
    return this.classroomsService.update(id, dto);
  }
}
