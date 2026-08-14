import { Body, Controller, Get, Param, Post, Query } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { CamooService } from "./camoo.service";
import { ApisungkuService } from "./apisungku.service";
import { CreateCashoutDto } from "./dto/create-cashout.dto";

@ApiTags("payments-gateway")
@ApiBearerAuth()
@Controller("payments/gateway")
export class PaymentsGatewayController {
  constructor(
    private readonly camoo: CamooService,
    private readonly apisungku: ApisungkuService,
    private readonly config: ConfigService,
  ) {}

  /**
   * apisungku est retenu des qu'il est configure, sauf demande explicite de
   * camoo : un fournisseur mal configure ne doit pas interrompre les paiements.
   */
  private get provider() {
    const demande = (this.config.get<string>("PAYMENT_PROVIDER") ?? "").toLowerCase();
    if (demande === "camoo") return this.camoo;
    return this.apisungku.isConfigured() ? this.apisungku : this.camoo;
  }

  @Post("cashout")
  @RequirePermissions("payments:cashout")
  @ApiOperation({ summary: "Initier un encaissement mobile money" })
  cashout(@Body() dto: CreateCashoutDto, @CurrentUser() user: AuthenticatedUser) {
    return this.provider.cashout(dto, user.tenantId, user.userId);
  }

  @Get("verify/:id")
  @RequirePermissions("payments:cashout")
  @ApiOperation({ summary: "Vérifier une transaction et synchroniser son statut" })
  verify(@Param("id") id: string) {
    return this.provider.verify(id);
  }

  @Get("account")
  @RequirePermissions("payments:cashout")
  @ApiOperation({ summary: "Solde du compte marchand" })
  account() {
    return this.provider.account();
  }

  @Get("providers")
  @RequirePermissions("payments:cashout")
  @ApiOperation({
    summary: "Opérateurs disponibles",
    description:
      "À utiliser pour construire le sélecteur de paiement : un opérateur en " +
      "panne n'y figure pas, et réapparaît sans redéploiement.",
  })
  providers(@Query("country") country?: string) {
    return this.apisungku.providers(country ?? "CMR");
  }
}
