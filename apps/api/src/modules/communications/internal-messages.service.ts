import { ForbiddenException, Injectable, NotFoundException } from "@nestjs/common";
import { buildPaginationMeta, DEFAULT_LIMIT, DEFAULT_PAGE, MAX_LIMIT, PaginatedResult, PaginationQuery } from "@scholaris/shared";
import { InternalMessage } from "@scholaris/prisma";
import { PrismaService } from "../../prisma/prisma.service";
import { CreateInternalMessageDto } from "./dto/create-internal-message.dto";

/** Messagerie interne pair-à-pair (§23.1 du guide, ex: enseignant ↔ parent) — sans fournisseur externe. */
@Injectable()
export class InternalMessagesService {
  constructor(private readonly prisma: PrismaService) {}

  create(tenantId: string, senderUserId: string, dto: CreateInternalMessageDto) {
    return this.prisma.internalMessage.create({
      data: {
        tenantId,
        senderUserId,
        recipientUserId: dto.recipientUserId,
        body: dto.body,
      },
    });
  }

  /** Boîte de réception/envoi de l'utilisateur courant (messages où il est expéditeur OU destinataire). */
  async findAllForUser(tenantId: string, userId: string, query: PaginationQuery): Promise<PaginatedResult<InternalMessage>> {
    const page = query.page && query.page > 0 ? query.page : DEFAULT_PAGE;
    const limit = query.limit && query.limit > 0 ? Math.min(query.limit, MAX_LIMIT) : DEFAULT_LIMIT;

    const where = { tenantId, OR: [{ senderUserId: userId }, { recipientUserId: userId }] };

    const [data, total] = await Promise.all([
      this.prisma.internalMessage.findMany({
        where,
        orderBy: { createdAt: "desc" },
        skip: (page - 1) * limit,
        take: limit,
      }),
      this.prisma.internalMessage.count({ where }),
    ]);

    return { data, meta: buildPaginationMeta(total, page, limit) };
  }

  /** Marque un message comme lu — seul le destinataire peut le faire. */
  async markAsRead(id: string, currentUserId: string) {
    const message = await this.prisma.internalMessage.findFirst({ where: { id } });
    if (!message) {
      throw new NotFoundException("Message introuvable");
    }
    if (message.recipientUserId !== currentUserId) {
      throw new ForbiddenException("Seul le destinataire peut marquer ce message comme lu");
    }
    return this.prisma.internalMessage.update({ where: { id }, data: { readAt: new Date() } });
  }
}
