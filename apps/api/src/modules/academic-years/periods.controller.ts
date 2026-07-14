import { Body, Controller, Get, Param, Post, Put, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiQuery, ApiTags } from "@nestjs/swagger";
import { PERMISSIONS } from "@scholaris/shared";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { PeriodsService } from "./periods.service";
import { CreatePeriodDto } from "./dto/create-period.dto";
import { UpdatePeriodStatusDto } from "./dto/update-period-status.dto";

@ApiTags("periods")
@ApiBearerAuth()
@Controller("periods")
export class PeriodsController {
  constructor(private readonly periodsService: PeriodsService) {}

  @Get()
  @RequirePermissions("periods:read")
  @ApiQuery({ name: "academicYearId", required: false })
  findAll(@CurrentUser() user: AuthenticatedUser, @Query("academicYearId") academicYearId?: string) {
    return this.periodsService.findAll(user.tenantId, { academicYearId });
  }

  @Post()
  @RequirePermissions("periods:create")
  @ApiOperation({ summary: "Crée une période (séquence/trimestre/semestre) — saisie fermée par défaut" })
  create(@Body() dto: CreatePeriodDto, @CurrentUser() user: AuthenticatedUser) {
    return this.periodsService.create(dto, user.tenantId);
  }

  @Put(":id/status")
  @RequirePermissions("periods:update")
  @ApiOperation({
    summary: "Ouvre/ferme/verrouille la saisie — la réouverture d'une période verrouillée exige periods:unlock (Admin)",
  })
  updateStatus(
    @Param("id") id: string,
    @Body() dto: UpdatePeriodStatusDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    const canUnlock = user.permissions.includes(PERMISSIONS.PERIODS_UNLOCK);
    return this.periodsService.updateStatus(id, dto.gradingStatus, user.tenantId, canUnlock);
  }
}
