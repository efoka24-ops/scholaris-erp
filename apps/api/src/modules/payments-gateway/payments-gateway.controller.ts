import { Body, Controller, Get, Param, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CamooService } from "./camoo.service";
import { CreateCashoutDto } from "./dto/create-cashout.dto";

@ApiTags("payments-gateway")
@ApiBearerAuth()
@Controller("payments/gateway")
export class PaymentsGatewayController {
  constructor(private readonly camoo: CamooService) {}

  @Post("cashout")
  @RequirePermissions("payments:cashout")
  @ApiOperation({ summary: "Initier un décaissement / paiement via CAMOO" })
  cashout(@Body() dto: CreateCashoutDto, @CurrentUser() user: AuthenticatedUser) {
    return this.camoo.cashout(dto, user.tenantId, user.userId);
  }

  @Get("verify/:id")
  @RequirePermissions("payments:cashout")
  @ApiOperation({ summary: "Vérifier une transaction CAMOO et synchroniser son statut" })
  verify(@Param("id") id: string) {
    return this.camoo.verify(id);
  }

  @Get("account")
  @RequirePermissions("payments:cashout")
  @ApiOperation({ summary: "Solde du compte marchand CAMOO" })
  account() {
    return this.camoo.account();
  }
}
