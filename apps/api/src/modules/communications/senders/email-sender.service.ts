import { Injectable, Logger } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import axios from "axios";
import { ChannelMessage, ChannelSendResult, ChannelSender } from "./channel-sender.interface";

const BREVO_ENDPOINT = "https://api.brevo.com/v3/smtp/email";

/** Envoi transactionnel via l'API Brevo (ex-Sendinblue) — §23.1 du guide. */
@Injectable()
export class EmailSenderService implements ChannelSender {
  private readonly logger = new Logger(EmailSenderService.name);

  constructor(private readonly config: ConfigService) {}

  async send(message: ChannelMessage): Promise<ChannelSendResult> {
    const apiKey = this.config.get<string>("BREVO_API_KEY");
    if (!apiKey) {
      this.logger.warn("BREVO_API_KEY non configurée — email non envoyé");
      return { error: "BREVO_API_KEY manquante" };
    }
    const senderEmail = this.config.get<string>("BREVO_SENDER_EMAIL", "no-reply@scholaris.dev");

    const response = await axios.post(
      BREVO_ENDPOINT,
      {
        sender: { email: senderEmail, name: "SCHOLARIS" },
        to: [{ email: message.to }],
        subject: message.subject ?? "SCHOLARIS",
        htmlContent: `<p>${message.body}</p>`,
      },
      {
        headers: {
          "api-key": apiKey,
          "Content-Type": "application/json",
          Accept: "application/json",
        },
      },
    );

    this.logger.log(`Email envoyé à ${message.to} (messageId=${response.data?.messageId})`);
    return { providerMessageId: response.data?.messageId };
  }
}
