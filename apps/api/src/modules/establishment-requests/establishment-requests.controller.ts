import { Body, Controller, Get, Param, Put, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiQuery, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { EstablishmentRequestsService } from "./establishment-requests.service";
import { RejectEstablishmentRequestDto } from "./dto/decision.dto";

/**
 * Traitement des demandes d'établissement par le Super Admin (permission
 * tenants:create, réservée au Super Admin plateforme).
 */
@ApiTags("establishment-requests")
@ApiBearerAuth()
@Controller("establishment-requests")
export class EstablishmentRequestsController {
  constructor(private readonly service: EstablishmentRequestsService) {}

  @Get()
  @RequirePermissions("tenants:create")
  @ApiQuery({ name: "status", required: false })
  findAll(@Query("status") status?: string) {
    return this.service.findAll(status);
  }

  @Put(":id/approve")
  @RequirePermissions("tenants:create")
  @ApiOperation({ summary: "Valider la demande : crée l'établissement + le compte directeur + email" })
  approve(@Param("id") id: string) {
    return this.service.approve(id);
  }

  @Put(":id/reject")
  @RequirePermissions("tenants:create")
  @ApiOperation({ summary: "Rejeter la demande d'établissement" })
  reject(@Param("id") id: string, @Body() dto: RejectEstablishmentRequestDto) {
    return this.service.reject(id, dto.reason);
  }
}
