import { Injectable, Logger } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import axios from "axios";
import { ChannelMessage, ChannelSendResult, ChannelSender } from "./channel-sender.interface";

const AFRICASTALKING_ENDPOINT = "https://api.africastalking.com/version1/messaging";

/** Envoi SMS via l'API Africa's Talking — §23.1 du guide (référence pour l'Afrique de l'Ouest/Centrale). */
@Injectable()
export class SmsSenderService implements ChannelSender {
  private readonly logger = new Logger(SmsSenderService.name);

  constructor(private readonly config: ConfigService) {}

  async send(message: ChannelMessage): Promise<ChannelSendResult> {
    const apiKey = this.config.getOrThrow<string>("AFRICASTALKING_API_KEY");
    const username = this.config.getOrThrow<string>("AFRICASTALKING_USERNAME");

    const body = new URLSearchParams({
      username,
      to: message.to,
      message: message.body,
    });

    const response = await axios.post(AFRICASTALKING_ENDPOINT, body.toString(), {
      headers: {
        apiKey,
        "Content-Type": "application/x-www-form-urlencoded",
        Accept: "application/json",
      },
    });

    const recipient = response.data?.SMSMessageData?.Recipients?.[0];
    this.logger.log(`SMS envoyé à ${message.to} (messageId=${recipient?.messageId})`);
    return { providerMessageId: recipient?.messageId };
  }
}
