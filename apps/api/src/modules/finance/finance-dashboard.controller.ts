import { Controller, Get, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiQuery, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { FinanceDashboardService } from "./finance-dashboard.service";

@ApiTags("finance-dashboard")
@ApiBearerAuth()
@Controller("finance")
export class FinanceDashboardController {
  constructor(private readonly financeDashboardService: FinanceDashboardService) {}

  @Get("dashboard")
  @RequirePermissions("finance-dashboard:read")
  @ApiQuery({ name: "academicYearId", required: false })
  getDashboard(@Query("academicYearId") academicYearId?: string) {
    return this.financeDashboardService.getDashboard(academicYearId);
  }
}
