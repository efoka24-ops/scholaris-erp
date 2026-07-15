import { Controller, Get, Post, Put, Body, Param, Query, UseGuards } from '@nestjs/common';
import { DisciplineService } from './discipline.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser } from '../../common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '../auth/jwt-payload.interface';
import { CreateIncidentDto } from './dto/create-incident.dto';
import { CreateSanctionDto } from './dto/create-sanction.dto';
import { FindIncidentsQueryDto } from './dto/find-incidents-query.dto';

@Controller('discipline')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class DisciplineController {
  constructor(private readonly disciplineService: DisciplineService) {}

  @Get('incidents')
  @RequirePermissions('discipline:read')
  async findAllIncidents(@CurrentUser() user: AuthenticatedUser, @Query() query: FindIncidentsQueryDto) {
    return this.disciplineService.findAllIncidents(user.tenantId, query);
  }

  @Get('incidents/student/:studentId')
  @RequirePermissions('discipline:read')
  async findStudentIncidents(@CurrentUser() user: AuthenticatedUser, @Param('studentId') studentId: string) {
    return this.disciplineService.findStudentIncidents(user.tenantId, studentId);
  }

  @Get('incidents/:id')
  @RequirePermissions('discipline:read')
  async findOneIncident(@CurrentUser() user: AuthenticatedUser, @Param('id') id: string) {
    return this.disciplineService.findOneIncident(user.tenantId, id);
  }

  @Post('incidents')
  @RequirePermissions('discipline:create')
  async createIncident(@CurrentUser() user: AuthenticatedUser, @Body() dto: CreateIncidentDto) {
    return this.disciplineService.createIncident(user.tenantId, dto, user.userId);
  }

  @Post('sanctions')
  @RequirePermissions('discipline:create')
  async createSanction(@CurrentUser() user: AuthenticatedUser, @Body() dto: CreateSanctionDto) {
    return this.disciplineService.createSanction(user.tenantId, dto, user.userId);
  }

  @Get('stats/:studentId')
  @RequirePermissions('discipline:read')
  async getStudentStats(@CurrentUser() user: AuthenticatedUser, @Param('studentId') studentId: string) {
    return this.disciplineService.getStudentStats(user.tenantId, studentId);
  }
}
