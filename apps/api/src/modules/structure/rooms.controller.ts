import { Body, Controller, Delete, Get, Param, Patch, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { RoomsService } from "./rooms.service";
import { CreateRoomDto } from "./dto/create-room.dto";
import { UpdateRoomDto } from "./dto/update-room.dto";

@ApiTags("structure/rooms")
@ApiBearerAuth()
@Controller("rooms")
export class RoomsController {
  constructor(private readonly roomsService: RoomsService) {}

  @Get()
  @RequirePermissions("rooms:read")
  findAll() {
    return this.roomsService.findAll();
  }

  @Get(":id")
  @RequirePermissions("rooms:read")
  findOne(@Param("id") id: string) {
    return this.roomsService.findOne(id);
  }

  @Post()
  @RequirePermissions("rooms:create")
  create(@Body() dto: CreateRoomDto, @CurrentUser() user: AuthenticatedUser) {
    return this.roomsService.create(dto, user.tenantId);
  }

  @Patch(":id")
  @RequirePermissions("rooms:update")
  update(@Param("id") id: string, @Body() dto: UpdateRoomDto) {
    return this.roomsService.update(id, dto);
  }

  @Delete(":id")
  @RequirePermissions("rooms:update")
  remove(@Param("id") id: string) {
    return this.roomsService.remove(id);
  }
}
