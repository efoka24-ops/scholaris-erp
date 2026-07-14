import { Controller, Get, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { AuditService } from "./audit.service";
import { AuditLogsQueryDto } from "./dto/audit-logs-query.dto";

@ApiTags("audit-logs")
@ApiBearerAuth()
@Controller("audit-logs")
export class AuditLogsController {
  constructor(private readonly auditService: AuditService) {}

  @Get()
  @RequirePermissions("audit-logs:read")
  @ApiOperation({ summary: "Journal d'audit paginé (filtres : utilisateur, action, ressource, dates)" })
  findAll(@Query() query: AuditLogsQueryDto, @CurrentUser() user: AuthenticatedUser) {
    return this.auditService.findAll(user.tenantId, query);
  }
}
