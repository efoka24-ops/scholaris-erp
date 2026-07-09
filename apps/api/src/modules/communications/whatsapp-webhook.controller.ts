import { Controller, ForbiddenException, Get, HttpCode, HttpStatus, Logger, Post, Query, Body } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import { ApiExcludeEndpoint } from "@nestjs/swagger";
import { Public } from "../../common/decorators/public.decorator";
import { WhatsappIntentResolverService } from "./chatbot/whatsapp-intent-resolver.service";

/**
 * Webhook entrant WhatsApp Cloud API (§23.3 du guide). Deux usages distincts, tous deux
 * publics (aucun JWT SCHOLARIS ne transite via Meta) :
 *  - GET  : handshake de vérification Meta (hub.mode/hub.verify_token/hub.challenge).
 *  - POST : notification d'un message entrant → chatbot à base de règles.
 *
 * Volontairement mince : on ne persiste pas les messages entrants en base (l'expéditeur
 * est un contact WhatsApp externe, pas forcément un User SCHOLARIS) — seul le résultat de
 * l'intention est journalisé/loggé. Le renvoi effectif de la réponse via l'API Meta
 * (WhatsappSenderService.send) est un branchement futur volontairement non câblé ici pour
 * rester un webhook simple ; voir le commentaire dans WhatsappIntentResolverService.
 */
@Controller("webhooks/whatsapp")
export class WhatsappWebhookController {
  private readonly logger = new Logger(WhatsappWebhookController.name);

  constructor(
    private readonly config: ConfigService,
    private readonly intentResolver: WhatsappIntentResolverService,
  ) {}

  @Public()
  @Get()
  @ApiExcludeEndpoint()
  verify(
    @Query("hub.mode") mode: string,
    @Query("hub.verify_token") verifyToken: string,
    @Query("hub.challenge") challenge: string,
  ): string {
    const expectedToken = this.config.get<string>("WHATSAPP_WEBHOOK_VERIFY_TOKEN");
    if (mode === "subscribe" && verifyToken && expectedToken && verifyToken === expectedToken) {
      return challenge;
    }
    throw new ForbiddenException("Jeton de vérification invalide");
  }

  @Public()
  @Post()
  @HttpCode(HttpStatus.OK)
  @ApiExcludeEndpoint()
  receive(@Body() payload: Record<string, unknown>): { received: true } {
    const messageText = this.extractMessageText(payload);
    if (messageText) {
      const reply = this.intentResolver.resolveIntent(messageText);
      this.logger.log(`Chatbot WhatsApp — message="${messageText}" → réponse="${reply}"`);
    }
    // Meta exige un 200 rapide, quel que soit le contenu, pour ne pas re-livrer l'event.
    return { received: true };
  }

  /** Extraction défensive du texte du premier message du payload Meta (structure imbriquée, tout est optionnel). */
  private extractMessageText(payload: Record<string, unknown>): string | undefined {
    const entry = (payload?.entry as any[] | undefined)?.[0];
    const change = entry?.changes?.[0];
    const message = change?.value?.messages?.[0];
    return message?.text?.body;
  }
}
