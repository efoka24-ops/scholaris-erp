import { Module } from "@nestjs/common";
import { CamooService } from "./camoo.service";
import { WebhookService } from "./webhook.service";
import { PaymentsGatewayController } from "./payments-gateway.controller";
import { WebhookController } from "./webhook.controller";

/**
 * Module d'intégration de la passerelle de paiement CAMOO Payment API.
 * Config lue via ConfigService (CAMOO_API_KEY / CAMOO_API_SECRET / CAMOO_BASE_URL).
 */
@Module({
  controllers: [PaymentsGatewayController, WebhookController],
  providers: [CamooService, WebhookService],
  exports: [CamooService, WebhookService],
})
export class PaymentsGatewayModule {}
