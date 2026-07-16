import { BadRequestException, Injectable, NotFoundException } from "@nestjs/common";
import { Prisma, Tenant } from "@scholaris/prisma";
import { calculationEngineSchema, CalculationEngineConfig } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { UpdateTenantDto } from "./dto/update-tenant.dto";

@Injectable()
export class TenantsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  async findOne(id: string): Promise<Tenant> {
    const tenant = await this.prisma.tenant.findFirst({ where: { id } });
    if (!tenant) {
      throw new NotFoundException("Établissement introuvable");
    }
    return tenant;
  }

  async update(id: string, dto: UpdateTenantDto): Promise<Tenant> {
    const before = await this.findOne(id);

    const updated = await this.prisma.tenant.update({ where: { id }, data: dto });

    await this.audit.log({
      action: "update",
      resource: "tenants",
      resourceId: id,
      oldValue: {
        name: before.name,
        address: before.address,
        phone: before.phone,
        email: before.email,
        logoUrl: before.logoUrl,
      },
      newValue: dto,
    });
    return updated;
  }

  /** Configuration du moteur de calcul (Tenant.config_json, §1.4 du guide). */
  async getConfig(id: string): Promise<unknown> {
    const tenant = await this.findOne(id);
    return tenant.configJson ?? null;
  }

  /**
   * Valide la configuration du moteur de calcul avec le schéma Zod partagé
   * (types d'évaluation, pondérations, seuils de mentions, arrondi, absence,
   * compensation LMD, échelle GPA) et rejette toute config invalide (400).
   */
  async updateConfig(id: string, config: unknown): Promise<CalculationEngineConfig> {
    const before = await this.findOne(id);

    const parsed = calculationEngineSchema.safeParse(config);
    if (!parsed.success) {
      const details = parsed.error.issues
        .map((issue) => `${issue.path.join(".") || "config"} : ${issue.message}`)
        .join(" ; ");
      throw new BadRequestException(`Configuration du moteur de calcul invalide — ${details}`);
    }

    // On fusionne avec le config_json existant (plutôt que de l'écraser) pour préserver
    // les clés hors moteur de calcul, ex: enabledModules (§ modules activés par établissement).
    const existing = (before.configJson as Record<string, unknown> | null) ?? {};
    const merged = { ...existing, ...parsed.data };

    await this.prisma.tenant.update({
      where: { id },
      data: { configJson: merged as unknown as Prisma.InputJsonValue },
    });

    await this.audit.log({
      action: "update",
      resource: "tenants:config",
      resourceId: id,
      oldValue: before.configJson,
      newValue: parsed.data,
    });
    return parsed.data;
  }

  /**
   * Modules/fonctionnalités activés pour cet établissement (§ CRUD établissement).
   * Stockés sous une clé dédiée de Tenant.config_json, séparée du moteur de calcul
   * (qui est, lui, validé et remplacé en intégralité par le schéma Zod strict).
   */
  async getEnabledModules(id: string): Promise<string[]> {
    const tenant = await this.findOne(id);
    const configJson = (tenant.configJson as Record<string, unknown> | null) ?? {};
    const enabledModules = configJson.enabledModules;
    return Array.isArray(enabledModules) ? (enabledModules as string[]) : [];
  }

  async updateEnabledModules(id: string, enabledModules: string[]): Promise<string[]> {
    const before = await this.findOne(id);
    const existing = (before.configJson as Record<string, unknown> | null) ?? {};
    const merged = { ...existing, enabledModules };

    await this.prisma.tenant.update({
      where: { id },
      data: { configJson: merged as unknown as Prisma.InputJsonValue },
    });

    await this.audit.log({
      action: "update",
      resource: "tenants:modules",
      resourceId: id,
      oldValue: existing.enabledModules ?? [],
      newValue: enabledModules,
    });

    return enabledModules;
  }
}
