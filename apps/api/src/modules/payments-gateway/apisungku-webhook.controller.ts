import { Controller, Headers, HttpCode, Post, Req, UnauthorizedException } from "@nestjs/common";
import { ApiOperation, ApiTags } from "@nestjs/swagger";
import type { RawBodyRequest } from "@nestjs/common";
import type { Request } from "express";

import { Public } from "../../common/decorators/public.decorator";
import { ApisungkuService } from "./apisungku.service";

/**
 * Endpoint PUBLIC (sans JWT) recevant les statuts finaux de la passerelle
 * apisungku. L'authenticite repose sur une signature HMAC portee par le corps
 * brut, pas sur un jeton : c'est la passerelle qui appelle, pas un utilisateur.
 */
@ApiTags("public")
@Controller("public/payments/apisungku")
export class ApisungkuWebhookController {
  constructor(private readonly apisungku: ApisungkuService) {}

  @Public()
  @Post("notify")
  @HttpCode(200)
  @ApiOperation({ summary: "Notification de paiement apisungku (signee HMAC)" })
  async notify(
    @Req() req: RawBodyRequest<Request>,
    @Headers() headers: Record<string, string | string[] | undefined>,
  ) {
    const rawBody = req.rawBody?.toString("utf8");

    if (!rawBody || !this.apisungku.verifyWebhook(rawBody, headers)) {
      throw new UnauthorizedException("Signature invalide");
    }

    const { data } = JSON.parse(rawBody) as { data: Record<string, string> };
    return this.apisungku.applyWebhook(data);
  }
}
