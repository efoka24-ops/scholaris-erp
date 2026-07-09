import { Injectable, Logger, OnModuleDestroy, OnModuleInit } from "@nestjs/common";
import { Prisma, PrismaClient } from "@scholaris/prisma";
import { RequestContextService } from "../common/context/request-context.service";

// Modèles avec deleted_at : jamais de suppression physique (§0.3 du guide).
const SOFT_DELETE_MODELS = new Set(["Tenant", "User"]);

// Modèles portant un tenant_id littéral, auto-filtrés par le tenant courant.
// Role.tenantId est nullable (rôles système) : voir mergeTenantWhere ci-dessous.
const TENANT_SCOPED_MODELS = new Set(["User", "Role", "AcademicYear"]);

const READ_ACTIONS = new Set(["findFirst", "findMany", "count", "aggregate", "groupBy"]);
const WRITE_MANY_ACTIONS = new Set(["updateMany", "deleteMany"]);

function mergeTenantWhere(where: Record<string, unknown> | undefined, model: string, tenantId: string) {
  const base = where ?? {};
  if (model === "Role") {
    // Un utilisateur voit les rôles de son établissement + les rôles système (tenantId null).
    return { AND: [base, { OR: [{ tenantId }, { tenantId: null }] }] };
  }
  return { ...base, tenantId };
}

@Injectable()
export class PrismaService extends PrismaClient implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(PrismaService.name);

  constructor(private readonly context: RequestContextService) {
    super();
  }

  async onModuleInit(): Promise<void> {
    this.registerSoftDeleteMiddleware();
    this.registerTenantScopingMiddleware();
    await this.$connect();
    this.logger.log("Connexion Prisma établie");
  }

  async onModuleDestroy(): Promise<void> {
    await this.$disconnect();
  }

  private registerSoftDeleteMiddleware(): void {
    this.$use(async (params, next) => {
      const { model, action } = params;
      if (!model || !SOFT_DELETE_MODELS.has(model)) {
        return next(params);
      }

      if (action === "delete") {
        params.action = "update";
        params.args.data = { deletedAt: new Date() };
      }
      if (action === "deleteMany") {
        params.action = "updateMany";
        params.args.data = { ...(params.args.data ?? {}), deletedAt: new Date() };
      }
      if (action === "findUnique" || action === "findFirst") {
        params.action = "findFirst";
        params.args.where = { ...params.args.where, deletedAt: null };
      }
      if (action === "findMany") {
        params.args.where = params.args.where ?? {};
        if (params.args.where.deletedAt === undefined) {
          params.args.where.deletedAt = null;
        }
      }
      return next(params);
    });
  }

  private registerTenantScopingMiddleware(): void {
    this.$use(async (params, next) => {
      const { model, action } = params;
      if (!model || !TENANT_SCOPED_MODELS.has(model)) {
        return next(params);
      }

      const tenantId = this.context.get("tenantId");
      if (!tenantId) {
        // Aucun tenant dans le contexte (ex: bootstrap du seed, tâche système) :
        // on laisse passer sans filtre plutôt que de bloquer, mais tout endpoint HTTP
        // normal passe par JwtAccessStrategy qui pose systématiquement le tenantId.
        return next(params);
      }

      params.args = params.args ?? {};

      if (action === "findUnique") {
        params.action = "findFirst";
        params.args.where = mergeTenantWhere(params.args.where, model, tenantId);
      } else if (READ_ACTIONS.has(action) || WRITE_MANY_ACTIONS.has(action)) {
        params.args.where = mergeTenantWhere(params.args.where, model, tenantId);
      } else if (action === "update" || action === "delete") {
        params.args.where = mergeTenantWhere(params.args.where, model, tenantId);
      }
      // "create" n'est pas auto-filtré : le service appelant doit poser tenantId
      // explicitement (une création n'a pas de "where" sur lequel greffer un filtre).

      return next(params);
    });
  }
}

export type { Prisma };
