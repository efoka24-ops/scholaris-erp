import { Controller, Get, Post, Put, Delete, Body, Param, UseGuards } from '@nestjs/common';
import { CateringService } from './catering.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser } from '../../common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '../auth/jwt-payload.interface';

@Controller('catering')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class CateringController {
  constructor(private readonly cateringService: CateringService) {}

  @Get('menus')
  @RequirePermissions('catering:read')
  async findAllMenus(@CurrentUser() user: AuthenticatedUser) {
    return this.cateringService.findAllMenus(user.tenantId);
  }

  @Post('menus')
  @RequirePermissions('catering:create')
  async createMenu(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.cateringService.createMenu(user.tenantId, dto);
  }

  @Get('subscriptions')
  @RequirePermissions('catering:read')
  async findAllSubscriptions(@CurrentUser() user: AuthenticatedUser) {
    return this.cateringService.findAllSubscriptions(user.tenantId);
  }

  @Post('subscriptions')
  @RequirePermissions('catering:create')
  async subscribe(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.cateringService.subscribe(user.tenantId, dto);
  }

  @Get('dorms')
  @RequirePermissions('catering:read')
  async findAllDorms(@CurrentUser() user: AuthenticatedUser) {
    return this.cateringService.findAllDorms(user.tenantId);
  }
}
