import { Controller, Get, Post, Put, Delete, Body, Param, UseGuards } from '@nestjs/common';
import { TransportService } from './transport.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser } from '../../common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '../auth/jwt-payload.interface';

@Controller('transport')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class TransportController {
  constructor(private readonly transportService: TransportService) {}

  @Get('routes')
  @RequirePermissions('transport:read')
  async findAllRoutes(@CurrentUser() user: AuthenticatedUser) {
    return this.transportService.findAllRoutes(user.tenantId);
  }

  @Post('routes')
  @RequirePermissions('transport:create')
  async createRoute(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.transportService.createRoute(user.tenantId, dto);
  }

  @Get('vehicles')
  @RequirePermissions('transport:read')
  async findAllVehicles(@CurrentUser() user: AuthenticatedUser) {
    return this.transportService.findAllVehicles(user.tenantId);
  }

  @Post('subscriptions')
  @RequirePermissions('transport:create')
  async subscribe(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.transportService.subscribe(user.tenantId, dto);
  }
}
