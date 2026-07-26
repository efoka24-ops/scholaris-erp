import { Injectable, Logger } from "@nestjs/common";
import { ConfigService } from "@nestjs/config";
import axios from "axios";
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

  /** Clé API Brevo — si présente, l'envoi passe par l'API HTTP (port 443). */
  private brevoKey(): string | undefined {
    return this.cfg("BREVO_API_KEY");
  }

  /** Fournisseur d'envoi effectif : Brevo (API HTTP) prioritaire, sinon SMTP. */
  private provider(): "brevo" | "smtp" | "none" {
    if (this.brevoKey()) return "brevo";
    if (this.cfg("SMTP_HOST") && this.cfg("SMTP_USER") && (this.cfg("SMTP_PASSWORD") ?? this.cfg("SMTP_PASS"))) {
      return "smtp";
    }
    return "none";
  }

  /** true si au moins un fournisseur (Brevo ou SMTP) est configuré. */
  isConfigured(): boolean {
    return this.provider() !== "none";
  }

  private senderEmail(): string | undefined {
    return this.cfg("SMTP_FROM_EMAIL") ?? this.cfg("SMTP_FROM") ?? this.cfg("SMTP_USER") ?? this.cfg("BREVO_SENDER_EMAIL");
  }

  /** Détail de la config (sans exposer le mot de passe) — pour le diagnostic. */
  describeConfig(): Record<string, unknown> {
    const port = Number(this.cfg("SMTP_PORT") ?? "587");
    return {
      provider: this.provider(),
      brevoConfigured: Boolean(this.brevoKey()),
      sender: this.senderEmail() ?? null,
      from: this.resolveFrom(),
      // Détail SMTP (utilisé seulement si provider = smtp)
      host: this.cfg("SMTP_HOST") ?? null,
      port,
      secure: this.cfg("SMTP_SECURE") === "true" || port === 465,
      user: this.cfg("SMTP_USER") ?? null,
      hasPassword: Boolean(this.cfg("SMTP_PASSWORD") ?? this.cfg("SMTP_PASS")),
      tlsInsecure: this.cfg("SMTP_TLS_INSECURE") !== "false",
    };
  }

  /**
   * Journal d'événements Brevo pour une adresse (delivered, blocked, spam,
   * softBounce, hardBounce…) — permet de savoir ce qu'est devenu un email.
   */
  async brevoEvents(email: string): Promise<unknown> {
    const key = this.brevoKey();
    if (!key) return { error: "Brevo non configuré (BREVO_API_KEY absent)" };
    try {
      const res = await axios.get("https://api.brevo.com/v3/smtp/statistics/events", {
        headers: { "api-key": key, accept: "application/json" },
        params: { email, limit: 50, days: 7 },
        timeout: 12_000,
      });
      return res.data;
    } catch (error) {
      const data = (error as any)?.response?.data;
      return { error: data?.message ?? (error as Error).message };
    }
  }

  /** Envoi via l'API HTTP transactionnelle Brevo (contourne le blocage SMTP sortant). */
  private async sendViaBrevo(params: { to: string; subject: string; html: string; text?: string }): Promise<MailSendResult> {
    const key = this.brevoKey()!;
    const email = this.senderEmail();
    if (!email) {
      return { sent: false, reason: "Brevo : expéditeur manquant (définir SMTP_FROM_EMAIL ou BREVO_SENDER_EMAIL)" };
    }
    const name = this.cfg("SMTP_FROM_NAME") ?? "SCHOLARIS";
    const to = params.to.split(",").map((e) => ({ email: e.trim() })).filter((r) => r.email);
    try {
      await axios.post(
        "https://api.brevo.com/v3/smtp/email",
        { sender: { name, email }, to, subject: params.subject, htmlContent: params.html, textContent: params.text },
        { headers: { "api-key": key, "content-type": "application/json", accept: "application/json" }, timeout: 15_000 },
      );
      this.logger.log(`Email (Brevo) envoyé à ${params.to} : ${params.subject}`);
      return { sent: true };
    } catch (error) {
      const data = (error as any)?.response?.data;
      const reason = `Brevo : ${data?.message ?? data?.code ?? (error as Error).message}`;
      this.logger.error(`Échec envoi Brevo à ${params.to} : ${reason}`);
      return { sent: false, reason };
    }
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

  /**
   * Teste la connexion SMTP (nodemailer verify). Renvoie l'erreur exacte si échec.
   * `override` permet de sonder un autre port/secure sans modifier la config
   * (diagnostic : trancher entre 587/STARTTLS et 465/SSL, ou détecter un blocage
   * du port sortant côté hébergeur).
   */
  async verifyConnection(override?: {
    port?: number;
    secure?: boolean;
    host?: string;
    user?: string;
    pass?: string;
  }): Promise<MailSendResult> {
    const forcedSmtp = Boolean(override?.host || override?.user || override?.pass);
    // Brevo prioritaire (sauf si on force un test SMTP avec des identifiants fournis).
    if (this.provider() === "brevo" && !forcedSmtp) {
      try {
        const res = await axios.get("https://api.brevo.com/v3/account", {
          headers: { "api-key": this.brevoKey()!, accept: "application/json" },
          timeout: 12_000,
        });
        return { sent: true, reason: `Brevo OK (${res.data?.email ?? "clé valide"})` };
      } catch (error) {
        const data = (error as any)?.response?.data;
        return { sent: false, reason: `Brevo : ${data?.message ?? (error as Error).message}` };
      }
    }

    const host = override?.host?.trim() ?? this.cfg("SMTP_HOST");
    const user = override?.user?.trim() ?? this.cfg("SMTP_USER");
    const pass = override?.pass ?? this.cfg("SMTP_PASSWORD") ?? this.cfg("SMTP_PASS");
    if (!host || !user || !pass) {
      return { sent: false, reason: "SMTP non configuré (SMTP_HOST / SMTP_USER / SMTP_PASSWORD manquants)" };
    }
    const port = override?.port ?? Number(this.cfg("SMTP_PORT") ?? "587");
    const secure = override?.secure ?? (this.cfg("SMTP_SECURE") === "true" || port === 465);
    const rejectUnauthorized = this.cfg("SMTP_TLS_INSECURE") === "false";
    const transport = nodemailer.createTransport({
      host,
      port,
      secure,
      auth: { user, pass },
      tls: { rejectUnauthorized },
      connectionTimeout: 12_000,
      greetingTimeout: 12_000,
      socketTimeout: 15_000,
    });
    try {
      await transport.verify();
      return { sent: true, reason: `Connexion OK (port ${port}, secure=${secure})` };
    } catch (error) {
      return { sent: false, reason: `port ${port} secure=${secure} → ${(error as Error).message}` };
    }
  }

  /** Envoi avec détail : { sent, reason } — reason renseigné en cas d'échec. */
  async sendDetailed(params: { to: string; subject: string; html: string; text?: string }): Promise<MailSendResult> {
    // Brevo (API HTTP) prioritaire : fonctionne même quand l'hébergeur bloque le SMTP.
    if (this.provider() === "brevo") {
      return this.sendViaBrevo(params);
    }
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
