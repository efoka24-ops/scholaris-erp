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

  /**
   * Résolution publique d'un établissement par son code (page vitrine, sans
   * authentification). Ne retourne que des informations non sensibles :
   * jamais configJson ni aucun champ interne.
   */
  async findPublicByCode(code: string): Promise<{
    id: string;
    code: string;
    name: string;
    type: Tenant["type"];
    address: string | null;
    logoUrl: string | null;
  }> {
    const tenant = await this.prisma.tenant.findFirst({ where: { code, deletedAt: null } });
    if (!tenant) {
      throw new NotFoundException("Établissement introuvable");
    }
    return {
      id: tenant.id,
      code: tenant.code,
      name: tenant.name,
      type: tenant.type,
      address: tenant.address,
      logoUrl: tenant.logoUrl,
    };
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

    await this.prisma.tenant.update({
      where: { id },
      data: { configJson: parsed.data as unknown as Prisma.InputJsonValue },
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
}
