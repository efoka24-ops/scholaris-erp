import { BadRequestException, Injectable, Logger, NotFoundException } from "@nestjs/common";
import { Channel, CommunicationMessage, CommunicationTemplate, MessageStatus } from "@scholaris/prisma";
import {
  buildPaginationMeta,
  DEFAULT_LIMIT,
  DEFAULT_PAGE,
  MAX_LIMIT,
  PaginatedResult,
  PaginationQuery,
} from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { SendMessageDto } from "./dto/send-message.dto";
import { EmailSenderService } from "./senders/email-sender.service";
import { SmsSenderService } from "./senders/sms-sender.service";
import { WhatsappSenderService } from "./senders/whatsapp-sender.service";
import { PushSenderService } from "./senders/push-sender.service";
import { InternalMessageService } from "./senders/internal-message.service";
import { ChannelSender } from "./senders/channel-sender.interface";

const PLACEHOLDER_REGEX = /\{([a-zA-Z0-9_]+)\}/g;

/** Rendu d'un template + résolution du canal + envoi + journalisation (§23.2 du guide). */
@Injectable()
export class CommunicationsService {
  private readonly logger = new Logger(CommunicationsService.name);
  private readonly senders: Record<Channel, ChannelSender>;

  constructor(
    private readonly prisma: PrismaService,
    email: EmailSenderService,
    sms: SmsSenderService,
    whatsapp: WhatsappSenderService,
    push: PushSenderService,
    internal: InternalMessageService,
  ) {
    this.senders = {
      [Channel.EMAIL]: email,
      [Channel.SMS]: sms,
      [Channel.WHATSAPP]: whatsapp,
      [Channel.PUSH]: push,
      [Channel.INTERNAL]: internal,
    };
  }

  /**
   * Substitution simple `{clé}` (pas un moteur de templating complet — §23.2 du guide).
   * Un placeholder sans variable correspondante est laissé tel quel dans le texte rendu,
   * pour rester visible/debuggable plutôt que de silencieusement disparaître.
   */
  renderTemplate(
    template: Pick<CommunicationTemplate, "subjectFr" | "subjectEn" | "bodyFr" | "bodyEn">,
    locale: "fr" | "en",
    variables: Record<string, string>,
  ): { subject?: string; body: string } {
    const subjectSource = locale === "en" ? (template.subjectEn ?? template.subjectFr ?? undefined) : (template.subjectFr ?? undefined);
    const bodySource = locale === "en" ? (template.bodyEn ?? template.bodyFr) : template.bodyFr;

    const substitute = (text: string) => text.replace(PLACEHOLDER_REGEX, (match, key) => variables[key] ?? match);

    return {
      subject: subjectSource ? substitute(subjectSource) : undefined,
      body: substitute(bodySource),
    };
  }

  /**
   * Détermine l'adresse/le numéro/l'identifiant à utiliser pour un canal donné, et
   * lève une erreur explicite si l'information nécessaire manque sur l'utilisateur
   * (ex: pas de téléphone renseigné pour un envoi SMS/WhatsApp).
   */
  private resolveContact(channel: Channel, recipient: { id: string; email: string; phone: string | null }): string {
    switch (channel) {
      case Channel.EMAIL:
        return recipient.email;
      case Channel.SMS:
      case Channel.WHATSAPP:
        if (!recipient.phone) {
          throw new Error(`Aucun numéro de téléphone pour l'utilisateur ${recipient.id}`);
        }
        return recipient.phone;
      case Channel.INTERNAL:
      case Channel.PUSH:
        return recipient.id;
      default:
        throw new Error(`Canal non supporté : ${channel}`);
    }
  }

  private async dispatch(channel: Channel, recipient: { id: string; email: string; phone: string | null }, subject: string | undefined, body: string) {
    const to = this.resolveContact(channel, recipient);
    return this.senders[channel].send({ to, subject, body });
  }

  /**
   * Point d'entrée générique "render + send" que les autres modules (Notes, Finance...)
   * appelleront plus tard pour déclencher une communication (bulletin disponible, relance
   * de paiement, etc.). Orchestration : rendu du template (si fourni) → résolution du canal
   * (override explicite > préférence du destinataire > canal par défaut du template) →
   * envoi → repli sur le canal secondaire si l'envoi échoue → journalisation systématique
   * dans CommunicationMessage, quel que soit le résultat final.
   */
  async send(tenantId: string, dto: SendMessageDto): Promise<CommunicationMessage> {
    let template: CommunicationTemplate | null = null;
    let subject = dto.subject;
    let body = dto.body;

    if (dto.templateId) {
      template = await this.prisma.communicationTemplate.findFirst({ where: { id: dto.templateId } });
      if (!template) {
        throw new NotFoundException("Modèle de communication introuvable");
      }
      const rendered = this.renderTemplate(template, dto.locale ?? "fr", dto.variables ?? {});
      subject = dto.subject ?? rendered.subject;
      body = rendered.body;
    }

    if (!body) {
      throw new BadRequestException("Un templateId ou un body est requis pour envoyer un message");
    }

    const recipient = await this.prisma.user.findFirst({ where: { id: dto.recipientUserId } });
    if (!recipient) {
      throw new NotFoundException("Destinataire introuvable");
    }

    const preference = await this.prisma.userChannelPreference.findFirst({ where: { userId: recipient.id } });

    const primaryChannel = dto.channel ?? preference?.preferredChannel ?? template?.channel;
    if (!primaryChannel) {
      throw new BadRequestException("Impossible de déterminer le canal d'envoi (aucun channel, préférence ou template)");
    }

    let finalChannel = primaryChannel;
    let status: MessageStatus = MessageStatus.PENDING;
    let providerMessageId: string | undefined;
    let errorMessage: string | undefined;

    try {
      const result = await this.dispatch(primaryChannel, recipient, subject, body);
      providerMessageId = result.providerMessageId;
      status = MessageStatus.SENT;
    } catch (primaryError) {
      const fallbackChannel = preference?.fallbackChannel;
      this.logger.warn(`Échec d'envoi via ${primaryChannel} pour ${recipient.id} : ${this.errorMessageOf(primaryError)}`);

      if (fallbackChannel && fallbackChannel !== primaryChannel) {
        try {
          const result = await this.dispatch(fallbackChannel, recipient, subject, body);
          providerMessageId = result.providerMessageId;
          status = MessageStatus.SENT;
          finalChannel = fallbackChannel;
        } catch (fallbackError) {
          status = MessageStatus.FAILED;
          errorMessage = this.errorMessageOf(fallbackError);
        }
      } else {
        status = MessageStatus.FAILED;
        errorMessage = this.errorMessageOf(primaryError);
      }
    }

    return this.prisma.communicationMessage.create({
      data: {
        tenantId,
        templateId: template?.id,
        channel: finalChannel,
        recipientUserId: recipient.id,
        subject,
        body,
        status,
        providerMessageId,
        errorMessage,
        sentAt: status === MessageStatus.SENT ? new Date() : null,
      },
    });
  }

  async findAll(tenantId: string, query: PaginationQuery): Promise<PaginatedResult<CommunicationMessage>> {
    const page = query.page && query.page > 0 ? query.page : DEFAULT_PAGE;
    const limit = query.limit && query.limit > 0 ? Math.min(query.limit, MAX_LIMIT) : DEFAULT_LIMIT;

    const [data, total] = await Promise.all([
      this.prisma.communicationMessage.findMany({
        where: { tenantId },
        orderBy: { createdAt: "desc" },
        skip: (page - 1) * limit,
        take: limit,
      }),
      this.prisma.communicationMessage.count({ where: { tenantId } }),
    ]);

    return { data, meta: buildPaginationMeta(total, page, limit) };
  }

  private errorMessageOf(error: unknown): string {
    return error instanceof Error ? error.message : "Erreur inconnue";
  }
}
