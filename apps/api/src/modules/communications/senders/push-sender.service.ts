import { Injectable, Logger } from "@nestjs/common";
import { ChannelMessage, ChannelSendResult, ChannelSender } from "./channel-sender.interface";

/**
 * Stub — Notifications Push (§23.1 du guide) : l'intégration Firebase Cloud Messaging (FCM)
 * réelle est différée tant qu'il n'existe pas de client mobile/PWA pour la recevoir.
 * On se contente ici de journaliser l'intention d'envoi ; CommunicationsService pose
 * ensuite le statut PENDING sur le CommunicationMessage correspondant (voir son
 * commentaire dédié). Quand un client mobile existera, remplacer le corps de cette
 * méthode par un appel à l'Admin SDK / API HTTP v1 de FCM avec le device token stocké
 * (à ajouter sur User ou un modèle dédié, absent en Phase 0).
 */
@Injectable()
export class PushSenderService implements ChannelSender {
  private readonly logger = new Logger(PushSenderService.name);

  async send(message: ChannelMessage): Promise<ChannelSendResult> {
    this.logger.log(`[STUB] Notification push destinée à ${message.to} — FCM non encore intégré (pas de client mobile)`);
    return {};
  }
}
