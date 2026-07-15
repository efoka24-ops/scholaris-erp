import { Controller, Get, Post, Put, Delete, Body, Param, UseGuards } from '@nestjs/common';
import { SchoolLifeService } from './school-life.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser } from '../../common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '../auth/jwt-payload.interface';

@Controller('school-life')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class SchoolLifeController {
  constructor(private readonly schoolLifeService: SchoolLifeService) {}

  @Get('clubs')
  @RequirePermissions('school-life:read')
  async findAllClubs(@CurrentUser() user: AuthenticatedUser) {
    return this.schoolLifeService.findAllClubs(user.tenantId);
  }

  @Post('clubs')
  @RequirePermissions('school-life:create')
  async createClub(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.schoolLifeService.createClub(user.tenantId, dto);
  }

  @Get('events')
  @RequirePermissions('school-life:read')
  async findAllEvents(@CurrentUser() user: AuthenticatedUser) {
    return this.schoolLifeService.findAllEvents(user.tenantId);
  }

  @Post('events')
  @RequirePermissions('school-life:create')
  async createEvent(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.schoolLifeService.createEvent(user.tenantId, dto);
  }
}
