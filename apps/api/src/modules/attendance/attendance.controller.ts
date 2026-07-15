import { Controller, Get, Post, Put, Body, Param, Query, UseGuards } from '@nestjs/common';
import { AttendanceService } from './attendance.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser } from '../../common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '../auth/jwt-payload.interface';
import { RecordAttendanceDto } from './dto/record-attendance.dto';
import { FindAttendanceQueryDto } from './dto/find-attendance-query.dto';

@Controller('attendance')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class AttendanceController {
  constructor(private readonly attendanceService: AttendanceService) {}

  @Get('classroom/:classroomId')
  @RequirePermissions('attendance:read')
  async findByClassroom(
    @CurrentUser() user: AuthenticatedUser,
    @Param('classroomId') classroomId: string,
    @Query() query: FindAttendanceQueryDto,
  ) {
    return this.attendanceService.findByClassroom(user.tenantId, classroomId, query);
  }

  @Get('student/:studentId')
  @RequirePermissions('attendance:read')
  async findByStudent(
    @CurrentUser() user: AuthenticatedUser,
    @Param('studentId') studentId: string,
    @Query() query: FindAttendanceQueryDto,
  ) {
    return this.attendanceService.findByStudent(user.tenantId, studentId, query);
  }

  @Get('stats/:classroomId')
  @RequirePermissions('attendance:read')
  async getStats(@CurrentUser() user: AuthenticatedUser, @Param('classroomId') classroomId: string) {
    return this.attendanceService.getStats(user.tenantId, classroomId);
  }

  @Post('record')
  @RequirePermissions('attendance:create')
  async recordAttendance(@CurrentUser() user: AuthenticatedUser, @Body() dto: RecordAttendanceDto) {
    return this.attendanceService.recordAttendance(user.tenantId, dto, user.userId);
  }

  @Put(':id/justify')
  @RequirePermissions('attendance:update')
  async justifyAbsence(
    @CurrentUser() user: AuthenticatedUser,
    @Param('id') id: string,
    @Body('justification') justification: string,
  ) {
    return this.attendanceService.justifyAbsence(user.tenantId, id, justification);
  }
}
