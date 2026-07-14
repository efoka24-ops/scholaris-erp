import { Injectable, Logger } from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import {
  buildPaginationMeta,
  DEFAULT_LIMIT,
  DEFAULT_PAGE,
  MAX_LIMIT,
  PaginatedResult,
} from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { RequestContextService } from "../../common/context/request-context.service";
import { AuditLogsQueryDto } from "./dto/audit-logs-query.dto";

export interface AuditEntry {
  /** create | update | delete | login | mfa-enable... */
  action: string;
  /** Ressource concernée (ex: "academic-years", "tenants"). */
  resource: string;
  resourceId?: string;
  oldValue?: unknown;
  newValue?: unknown;
}

/**
 * Journalisation des opérations sensibles (création/modification/suppression).
 * L'utilisateur et l'IP sont lus dans le RequestContext (posés par le middleware
 * et la stratégie JWT) : les services appelants n'ont que le "quoi" à fournir.
 * Un échec d'écriture du journal ne doit jamais faire échouer l'opération métier.
 */
@Injectable()
export class AuditService {
  private readonly logger = new Logger(AuditService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly context: RequestContextService,
  ) {}

  async log(entry: AuditEntry): Promise<void> {
    try {
      await this.prisma.auditLog.create({
        data: {
          userId: this.context.get("userId") ?? null,
          action: entry.action,
          resource: entry.resource,
          resourceId: entry.resourceId,
          oldValue: this.toJson(entry.oldValue),
          newValue: this.toJson(entry.newValue),
          ipAddress: this.context.get("ip") ?? null,
        },
      });
    } catch (error) {
      this.logger.error(
        `Échec d'écriture du journal d'audit (${entry.action} ${entry.resource}) : ${
          error instanceof Error ? error.message : "erreur inconnue"
        }`,
      );
    }
  }

  /**
   * Journal paginé, restreint aux utilisateurs de l'établissement courant
   * (AuditLog ne porte pas de tenant_id : l'isolation passe par la relation user).
   */
  async findAll(tenantId: string, query: AuditLogsQueryDto): Promise<PaginatedResult<unknown>> {
    const page = query.page && query.page > 0 ? query.page : DEFAULT_PAGE;
    const limit = query.limit && query.limit > 0 ? Math.min(query.limit, MAX_LIMIT) : DEFAULT_LIMIT;

    const where: Prisma.AuditLogWhereInput = {
      user: { tenantId },
      ...(query.userId ? { userId: query.userId } : {}),
      ...(query.action ? { action: query.action } : {}),
      ...(query.resource ? { resource: query.resource } : {}),
      ...(query.dateFrom || query.dateTo
        ? {
            timestamp: {
              ...(query.dateFrom ? { gte: new Date(query.dateFrom) } : {}),
              ...(query.dateTo ? { lte: new Date(query.dateTo) } : {}),
            },
          }
        : {}),
    };

    const [data, total] = await Promise.all([
      this.prisma.auditLog.findMany({
        where,
        orderBy: { timestamp: "desc" },
        skip: (page - 1) * limit,
        take: limit,
        include: { user: { select: { id: true, email: true, firstName: true, lastName: true } } },
      }),
      this.prisma.auditLog.count({ where }),
    ]);

    return { data, meta: buildPaginationMeta(total, page, limit) };
  }

  private toJson(value: unknown): Prisma.InputJsonValue | undefined {
    if (value === undefined || value === null) {
      return undefined;
    }
    // Passage par JSON.parse/stringify pour neutraliser Dates, undefined imbriqués, etc.
    return JSON.parse(JSON.stringify(value)) as Prisma.InputJsonValue;
  }
}
