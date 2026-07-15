import { Controller, Get, Post, Put, Delete, Body, Param, Query, UseGuards } from '@nestjs/common';
import { AssetsService } from './assets.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser } from '../../common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '../auth/jwt-payload.interface';

@Controller('assets')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class AssetsController {
  constructor(private readonly assetsService: AssetsService) {}

  @Get()
  @RequirePermissions('assets:read')
  async findAll(@CurrentUser() user: AuthenticatedUser, @Query() query: any) {
    return this.assetsService.findAll(user.tenantId, query);
  }

  @Get(':id')
  @RequirePermissions('assets:read')
  async findOne(@CurrentUser() user: AuthenticatedUser, @Param('id') id: string) {
    return this.assetsService.findOne(user.tenantId, id);
  }

  @Post()
  @RequirePermissions('assets:create')
  async create(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.assetsService.create(user.tenantId, dto);
  }

  @Put(':id')
  @RequirePermissions('assets:update')
  async update(@CurrentUser() user: AuthenticatedUser, @Param('id') id: string, @Body() dto: any) {
    return this.assetsService.update(user.tenantId, id, dto);
  }

  @Delete(':id')
  @RequirePermissions('assets:delete')
  async remove(@CurrentUser() user: AuthenticatedUser, @Param('id') id: string) {
    return this.assetsService.remove(user.tenantId, id);
  }

  @Post(':id/maintenance')
  @RequirePermissions('assets:update')
  async recordMaintenance(@CurrentUser() user: AuthenticatedUser, @Param('id') id: string, @Body() dto: any) {
    return this.assetsService.recordMaintenance(user.tenantId, id, dto);
  }
}
