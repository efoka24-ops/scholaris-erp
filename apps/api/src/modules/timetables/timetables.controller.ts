import { Controller, Get, Post, Put, Delete, Body, Param, Query, UseGuards } from '@nestjs/common';
import { TimetablesService } from './timetables.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser } from '../../common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '../auth/jwt-payload.interface';
import { CreateTimetableDto } from './dto/create-timetable.dto';
import { UpdateTimetableDto } from './dto/update-timetable.dto';
import { FindTimetablesQueryDto } from './dto/find-timetables-query.dto';

@Controller('timetables')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class TimetablesController {
  constructor(private readonly timetablesService: TimetablesService) {}

  @Get()
  @RequirePermissions('timetables:read')
  async findAll(@CurrentUser() user: AuthenticatedUser, @Query() query: FindTimetablesQueryDto) {
    return this.timetablesService.findAll(user.tenantId, query);
  }

  @Get('classroom/:classroomId')
  @RequirePermissions('timetables:read')
  async findByClassroom(@CurrentUser() user: AuthenticatedUser, @Param('classroomId') classroomId: string) {
    return this.timetablesService.findByClassroom(user.tenantId, classroomId);
  }

  @Get('teacher/:teacherId')
  @RequirePermissions('timetables:read')
  async findByTeacher(@CurrentUser() user: AuthenticatedUser, @Param('teacherId') teacherId: string) {
    return this.timetablesService.findByTeacher(user.tenantId, teacherId);
  }

  @Get(':id')
  @RequirePermissions('timetables:read')
  async findOne(@CurrentUser() user: AuthenticatedUser, @Param('id') id: string) {
    return this.timetablesService.findOne(user.tenantId, id);
  }

  @Post()
  @RequirePermissions('timetables:create')
  async create(@CurrentUser() user: AuthenticatedUser, @Body() createDto: CreateTimetableDto) {
    return this.timetablesService.create(user.tenantId, createDto);
  }

  @Put(':id')
  @RequirePermissions('timetables:update')
  async update(
    @CurrentUser() user: AuthenticatedUser,
    @Param('id') id: string,
    @Body() updateDto: UpdateTimetableDto,
  ) {
    return this.timetablesService.update(user.tenantId, id, updateDto);
  }

  @Delete(':id')
  @RequirePermissions('timetables:delete')
  async remove(@CurrentUser() user: AuthenticatedUser, @Param('id') id: string) {
    return this.timetablesService.remove(user.tenantId, id);
  }

  @Post('generate/:classroomId')
  @RequirePermissions('timetables:create')
  async generateAutomatic(
    @CurrentUser() user: AuthenticatedUser,
    @Param('classroomId') classroomId: string,
  ) {
    return this.timetablesService.generateAutomatic(user.tenantId, classroomId);
  }
}
