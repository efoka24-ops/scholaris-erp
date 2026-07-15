import { Injectable, Logger } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import axios from "axios";
import { ChannelMessage, ChannelSendResult, ChannelSender } from "./channel-sender.interface";

/** Envoi de message WhatsApp via l'API Meta Cloud (WhatsApp Business) — §23.1 du guide. */
@Injectable()
export class WhatsappSenderService implements ChannelSender {
  private readonly logger = new Logger(WhatsappSenderService.name);

  constructor(private readonly config: ConfigService) {}

  async send(message: ChannelMessage): Promise<ChannelSendResult> {
    const token = this.config.get<string>("WHATSAPP_CLOUD_API_TOKEN");
    const phoneNumberId = this.config.get<string>("WHATSAPP_PHONE_NUMBER_ID");
    if (!token || !phoneNumberId) {
      this.logger.warn("WHATSAPP_CLOUD_API_TOKEN/PHONE_NUMBER_ID non configurées — WhatsApp non envoyé");
      return { error: "WhatsApp non configuré" };
    }
    const endpoint = `https://graph.facebook.com/v20.0/${phoneNumberId}/messages`;

    const response = await axios.post(
      endpoint,
      {
        messaging_product: "whatsapp",
        to: message.to,
        type: "text",
        text: { body: message.body },
      },
      {
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
        },
      },
    );

    const providerMessageId = response.data?.messages?.[0]?.id;
    this.logger.log(`WhatsApp envoyé à ${message.to} (messageId=${providerMessageId})`);
    return { providerMessageId };
  }
}
