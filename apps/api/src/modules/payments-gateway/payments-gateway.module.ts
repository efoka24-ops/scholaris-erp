import { Module } from "@nestjs/common";
import { CamooService } from "./camoo.service";
import { ApisungkuService } from "./apisungku.service";
import { WebhookService } from "./webhook.service";
import { PaymentsGatewayController } from "./payments-gateway.controller";
import { WebhookController } from "./webhook.controller";
import { ApisungkuWebhookController } from "./apisungku-webhook.controller";

/**
 * Intégration des passerelles de paiement.
 *
 * Deux fournisseurs coexistent, choisis par PAYMENT_PROVIDER :
 *   - apisungku : passerelle mutualisée, seule à détenir les tokens opérateur ;
 *   - camoo     : intégration historique, conservée en repli.
 *
 * Config lue via ConfigService, jamais en dur.
 */
@Module({
  controllers: [PaymentsGatewayController, WebhookController, ApisungkuWebhookController],
  providers: [CamooService, ApisungkuService, WebhookService],
  exports: [CamooService, ApisungkuService, WebhookService],
})
export class PaymentsGatewayModule {}
