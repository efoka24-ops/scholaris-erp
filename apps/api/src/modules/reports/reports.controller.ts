import { Controller, Get, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { ReportsService } from "./reports.service";

@ApiTags("reports")
@ApiBearerAuth()
@Controller("reports")
export class ReportsController {
  constructor(private readonly reports: ReportsService) {}

  @Get("level")
  @RequirePermissions("reports:read")
  @ApiOperation({ summary: "Rapport académique par niveau (moyennes, taux, distribution, comparaison classes)" })
  levelReport(
    @Query("levelId") levelId: string,
    @Query("periodId") periodId: string,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.reports.levelReport(levelId, periodId, user.tenantId);
  }
}
