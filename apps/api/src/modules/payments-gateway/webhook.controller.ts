import { Controller, Get, Query } from "@nestjs/common";
import { ApiOperation, ApiTags } from "@nestjs/swagger";
import { Public } from "../../common/decorators/public.decorator";
import { WebhookService } from "./webhook.service";

/**
 * Endpoint PUBLIC (sans JWT) recevant les notifications CAMOO.
 * CAMOO envoie un HTTP GET signé (query param `sig`, HMAC-SHA256 dérivé de
 * l'API Secret). La route est idempotente : elle renvoie 200 { received: true }
 * quand la signature est valide, 401 sinon.
 */
@ApiTags("public")
@Controller("public/payments/camoo")
export class WebhookController {
  constructor(private readonly webhook: WebhookService) {}

  @Public()
  @Get("notify")
  @ApiOperation({ summary: "Notification de paiement CAMOO (webhook signé HMAC)" })
  notify(@Query() query: Record<string, string>) {
    return this.webhook.handleNotification(query);
  }
}
