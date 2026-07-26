import { Injectable, Logger } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import * as nodemailer from "nodemailer";

/**
 * Envoi d'emails transactionnels via SMTP (nodemailer). Configuration lue
 * EXCLUSIVEMENT depuis l'environnement — aucun identifiant en dur dans le code :
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD (ou SMTP_PASS), SMTP_SECURE,
 *   SMTP_FROM_EMAIL / SMTP_FROM, SMTP_FROM_NAME,
 *   SMTP_TLS_INSECURE (=true pour accepter un certificat auto-signé/privé — par
 *     défaut true car de nombreux serveurs mail d'hébergeurs CM ont un certificat
 *     ne correspondant pas exactement au host ; mettre "false" pour l'imposer).
 * Si la config est absente, l'envoi est ignoré proprement plutôt que de faire
 * planter le flux appelant (ex: validation d'un établissement).
 */
export interface MailSendResult {
  sent: boolean;
  reason?: string;
}

@Injectable()
export class SmtpMailService {
  private readonly logger = new Logger(SmtpMailService.name);

  constructor(private readonly config: ConfigService) {}

  /**
   * Lit une variable d'env en retirant les espaces/tabulations parasites
   * (un simple copier-coller dans Railway peut introduire une tabulation en tête,
   * ce qui casse la résolution DNS du host — erreur EBADNAME).
   */
  private cfg(key: string): string | undefined {
    const value = this.config.get<string>(key);
    return value == null ? undefined : value.trim() || undefined;
  }

  /** true si les variables minimales (host, user, pass) sont présentes. */
  isConfigured(): boolean {
    const host = this.cfg("SMTP_HOST");
    const user = this.cfg("SMTP_USER");
    const pass = this.cfg("SMTP_PASSWORD") ?? this.cfg("SMTP_PASS");
    return Boolean(host && user && pass);
  }

  /** Détail de la config (sans exposer le mot de passe) — pour le diagnostic. */
  describeConfig(): Record<string, unknown> {
    const port = Number(this.cfg("SMTP_PORT") ?? "587");
    return {
      host: this.cfg("SMTP_HOST") ?? null,
      port,
      secure: this.cfg("SMTP_SECURE") === "true" || port === 465,
      user: this.cfg("SMTP_USER") ?? null,
      hasPassword: Boolean(this.cfg("SMTP_PASSWORD") ?? this.cfg("SMTP_PASS")),
      from: this.resolveFrom(),
      tlsInsecure: this.cfg("SMTP_TLS_INSECURE") !== "false",
    };
  }

  private buildTransport(): nodemailer.Transporter | null {
    const host = this.cfg("SMTP_HOST");
    const user = this.cfg("SMTP_USER");
    const pass = this.cfg("SMTP_PASSWORD") ?? this.cfg("SMTP_PASS");
    if (!host || !user || !pass) {
      return null;
    }
    const port = Number(this.cfg("SMTP_PORT") ?? "587");
    const secure = this.cfg("SMTP_SECURE") === "true" || port === 465;
    // Par défaut on n'impose pas la validation stricte du certificat : beaucoup de
    // serveurs mail privés (ex: mx-*.ewodi.net) présentent un certificat dont le CN
    // ne correspond pas exactement au host, ce qui faisait échouer l'envoi
    // silencieusement. Mettre SMTP_TLS_INSECURE="false" pour réactiver la vérif.
    const rejectUnauthorized = this.cfg("SMTP_TLS_INSECURE") === "false";
    return nodemailer.createTransport({
      host,
      port,
      secure,
      auth: { user, pass },
      tls: { rejectUnauthorized },
      connectionTimeout: 15_000,
      greetingTimeout: 15_000,
      socketTimeout: 20_000,
    });
  }

  private resolveFrom(): string {
    const fromName = this.cfg("SMTP_FROM_NAME");
    const fromEmail =
      this.cfg("SMTP_FROM_EMAIL") ??
      this.cfg("SMTP_FROM") ??
      this.cfg("SMTP_USER") ??
      "no-reply@scholaris.cm";
    return fromName ? `${fromName} <${fromEmail}>` : fromEmail;
  }

  /** Teste la connexion SMTP (nodemailer verify). Renvoie l'erreur exacte si échec. */
  async verifyConnection(): Promise<MailSendResult> {
    const transport = this.buildTransport();
    if (!transport) {
      return { sent: false, reason: "SMTP non configuré (SMTP_HOST / SMTP_USER / SMTP_PASSWORD manquants)" };
    }
    try {
      await transport.verify();
      return { sent: true };
    } catch (error) {
      return { sent: false, reason: (error as Error).message };
    }
  }

  /** Envoi avec détail : { sent, reason } — reason renseigné en cas d'échec. */
  async sendDetailed(params: { to: string; subject: string; html: string; text?: string }): Promise<MailSendResult> {
    const transport = this.buildTransport();
    if (!transport) {
      const reason = "SMTP non configuré (SMTP_HOST / SMTP_USER / SMTP_PASSWORD manquants)";
      this.logger.warn(`${reason} — email « ${params.subject} » à ${params.to} non envoyé`);
      return { sent: false, reason };
    }
    try {
      await transport.sendMail({
        from: this.resolveFrom(),
        to: params.to,
        subject: params.subject,
        html: params.html,
        text: params.text,
      });
      this.logger.log(`Email envoyé à ${params.to} : ${params.subject}`);
      return { sent: true };
    } catch (error) {
      const reason = (error as Error).message;
      this.logger.error(`Échec de l'envoi email à ${params.to} : ${reason}`);
      return { sent: false, reason };
    }
  }

  /** Retourne true si l'email a été envoyé, false sinon (compat historique). */
  async send(params: { to: string; subject: string; html: string; text?: string }): Promise<boolean> {
    return (await this.sendDetailed(params)).sent;
  }
}
