import { Injectable, Logger } from "@nestjs/common";
import { ChannelMessage, ChannelSendResult, ChannelSender } from "./channel-sender.interface";

/**
 * Canal INTERNAL du moteur de templates (§23.1 du guide) : aucun appel externe, le
 * CommunicationMessage écrit par CommunicationsService EST la trace de la notification
 * in-app — il n'y a rien de plus à persister ici.
 *
 * Ne pas confondre avec InternalMessagesService (module racine communications/) qui gère
 * la vraie "messagerie interne" pair-à-pair (§23.1, table InternalMessage avec un
 * senderUserId humain) exposée via InternalMessagesController : un envoi de template
 * déclenché par un autre module (ex: Notes, Finance) n'a pas d'utilisateur expéditeur,
 * donc il ne peut pas alimenter InternalMessage (senderUserId non nullable).
 */
@Injectable()
export class InternalMessageService implements ChannelSender {
  private readonly logger = new Logger(InternalMessageService.name);

  async send(message: ChannelMessage): Promise<ChannelSendResult> {
    this.logger.log(`Notification interne consignée pour l'utilisateur ${message.to}`);
    return {};
  }
}
