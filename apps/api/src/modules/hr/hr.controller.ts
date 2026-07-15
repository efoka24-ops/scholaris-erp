import { Controller, Get, Post, Put, Delete, Body, Param, Query, UseGuards } from '@nestjs/common';
import { HrService } from './hr.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser } from '../../common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '../auth/jwt-payload.interface';

@Controller('hr')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class HrController {
  constructor(private readonly hrService: HrService) {}

  @Get('employees')
  @RequirePermissions('hr:read')
  async findAllEmployees(@CurrentUser() user: AuthenticatedUser, @Query() query: any) {
    return this.hrService.findAllEmployees(user.tenantId, query);
  }

  @Get('employees/:id')
  @RequirePermissions('hr:read')
  async findOneEmployee(@CurrentUser() user: AuthenticatedUser, @Param('id') id: string) {
    return this.hrService.findOneEmployee(user.tenantId, id);
  }

  @Post('employees')
  @RequirePermissions('hr:create')
  async createEmployee(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.hrService.createEmployee(user.tenantId, dto);
  }

  @Get('payroll/:month')
  @RequirePermissions('hr:read')
  async getPayroll(@CurrentUser() user: AuthenticatedUser, @Param('month') month: string) {
    return this.hrService.getPayroll(user.tenantId, month);
  }

  @Post('payroll/generate')
  @RequirePermissions('hr:create')
  async generatePayroll(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.hrService.generatePayroll(user.tenantId, dto);
  }

  @Get('leave-requests')
  @RequirePermissions('hr:read')
  async findLeaveRequests(@CurrentUser() user: AuthenticatedUser) {
    return this.hrService.findLeaveRequests(user.tenantId);
  }

  @Post('leave-requests')
  @RequirePermissions('hr:create')
  async createLeaveRequest(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.hrService.createLeaveRequest(user.tenantId, dto, user.userId);
  }

  @Put('leave-requests/:id/approve')
  @RequirePermissions('hr:update')
  async approveLeave(@CurrentUser() user: AuthenticatedUser, @Param('id') id: string) {
    return this.hrService.approveLeave(user.tenantId, id);
  }
}
