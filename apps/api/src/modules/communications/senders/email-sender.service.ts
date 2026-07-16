import { Injectable, Logger } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import axios from "axios";
import * as nodemailer from "nodemailer";
import { ChannelMessage, ChannelSendResult, ChannelSender } from "./channel-sender.interface";

const BREVO_ENDPOINT = "https://api.brevo.com/v3/smtp/email";

/**
 * Envoi transactionnel — §23.1 du guide.
 * Deux fournisseurs supportés, choisis selon la configuration disponible :
 * 1) SMTP classique (variables SMTP_HOST/SMTP_USER/SMTP_PASSWORD) — utilisé si SMTP_HOST est défini.
 * 2) API Brevo (ex-Sendinblue) — utilisé en repli si BREVO_API_KEY est défini.
 * Aucun identifiant n'est codé en dur : tout provient des variables d'environnement,
 * jamais commitées (voir .env.production.example pour les noms attendus).
 */
@Injectable()
export class EmailSenderService implements ChannelSender {
  private readonly logger = new Logger(EmailSenderService.name);
  private smtpTransporter: nodemailer.Transporter | null = null;

  constructor(private readonly config: ConfigService) {}

  async send(message: ChannelMessage): Promise<ChannelSendResult> {
    const smtpHost = this.config.get<string>("SMTP_HOST");
    if (smtpHost) {
      return this.sendViaSmtp(message, smtpHost);
    }
    return this.sendViaBrevo(message);
  }

  private getSmtpTransporter(host: string): nodemailer.Transporter {
    if (!this.smtpTransporter) {
      const port = Number(this.config.get<string>("SMTP_PORT", "587"));
      this.smtpTransporter = nodemailer.createTransport({
        host,
        port,
        secure: port === 465,
        auth: {
          user: this.config.get<string>("SMTP_USER"),
          pass: this.config.get<string>("SMTP_PASSWORD"),
        },
      });
    }
    return this.smtpTransporter;
  }

  private async sendViaSmtp(message: ChannelMessage, host: string): Promise<ChannelSendResult> {
    const user = this.config.get<string>("SMTP_USER");
    const pass = this.config.get<string>("SMTP_PASSWORD");
    if (!user || !pass) {
      this.logger.warn("SMTP_HOST défini mais SMTP_USER/SMTP_PASSWORD manquants — email non envoyé");
      return { error: "Identifiants SMTP manquants" };
    }
    const fromName = this.config.get<string>("SMTP_FROM_NAME", "SCHOLARIS");
    const fromEmail = this.config.get<string>("SMTP_FROM_EMAIL", user);

    const transporter = this.getSmtpTransporter(host);
    const info = await transporter.sendMail({
      from: `"${fromName}" <${fromEmail}>`,
      to: message.to,
      subject: message.subject ?? "SCHOLARIS",
      html: `<p>${message.body}</p>`,
    });

    this.logger.log(`Email envoyé à ${message.to} via SMTP (messageId=${info.messageId})`);
    return { providerMessageId: info.messageId };
  }

  private async sendViaBrevo(message: ChannelMessage): Promise<ChannelSendResult> {
    const apiKey = this.config.get<string>("BREVO_API_KEY");
    if (!apiKey) {
      this.logger.warn("Aucun fournisseur email configuré (ni SMTP_HOST ni BREVO_API_KEY) — email non envoyé");
      return { error: "Aucun fournisseur email configuré" };
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

    this.logger.log(`Email envoyé à ${message.to} via Brevo (messageId=${response.data?.messageId})`);
    return { providerMessageId: response.data?.messageId };
  }
}
