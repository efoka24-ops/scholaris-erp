import { Injectable, Logger } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import * as nodemailer from "nodemailer";

/**
 * Envoi d'emails transactionnels via SMTP (nodemailer). Configuration lue
 * EXCLUSIVEMENT depuis l'environnement — aucun identifiant en dur dans le code :
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_SECURE
 * Si la config est absente, l'envoi est ignoré proprement (log warn) plutôt que
 * de faire planter le flux appelant (ex: validation d'un établissement).
 */
@Injectable()
export class SmtpMailService {
  private readonly logger = new Logger(SmtpMailService.name);

  constructor(private readonly config: ConfigService) {}

  private buildTransport(): nodemailer.Transporter | null {
    const host = this.config.get<string>("SMTP_HOST");
    const user = this.config.get<string>("SMTP_USER");
    // Accepte les deux conventions de nommage (SMTP_PASSWORD est celle documentée
    // dans .env.production.example ; SMTP_PASS reste accepté en repli).
    const pass = this.config.get<string>("SMTP_PASSWORD") ?? this.config.get<string>("SMTP_PASS");
    if (!host || !user || !pass) {
      return null;
    }
    const port = Number(this.config.get<string>("SMTP_PORT") ?? "587");
    const secure = this.config.get<string>("SMTP_SECURE") === "true" || port === 465;
    return nodemailer.createTransport({ host, port, secure, auth: { user, pass } });
  }

  private resolveFrom(): string {
    const fromName = this.config.get<string>("SMTP_FROM_NAME");
    const fromEmail =
      this.config.get<string>("SMTP_FROM_EMAIL") ??
      this.config.get<string>("SMTP_FROM") ??
      this.config.get<string>("SMTP_USER")!;
    return fromName ? `${fromName} <${fromEmail}>` : fromEmail;
  }

  /** Retourne true si l'email a été envoyé, false s'il a été ignoré (SMTP non configuré). */
  async send(params: { to: string; subject: string; html: string; text?: string }): Promise<boolean> {
    const transport = this.buildTransport();
    if (!transport) {
      this.logger.warn(`SMTP non configuré — email « ${params.subject} » à ${params.to} non envoyé`);
      return false;
    }
    const from = this.resolveFrom();
    try {
      await transport.sendMail({
        from,
        to: params.to,
        subject: params.subject,
        html: params.html,
        text: params.text,
      });
      this.logger.log(`Email envoyé à ${params.to} : ${params.subject}`);
      return true;
    } catch (error) {
      this.logger.error(`Échec de l'envoi email à ${params.to} : ${(error as Error).message}`);
      return false;
    }
  }
}
