import { BadRequestException, ConflictException, Injectable, NotFoundException } from "@nestjs/common";
import { Prisma, Tenant, TenantStatus } from "@scholaris/prisma";
import { calculationEngineSchema, CalculationEngineConfig } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { CreateTenantDto } from "./dto/create-tenant.dto";
import { UpdateTenantDto } from "./dto/update-tenant.dto";

export interface BulletinGroup {
  number: number;
  label: string;
}
export interface BulletinGroupsConfig {
  groups: BulletinGroup[];
  assignments: Record<string, number>; // subjectId -> group number
  categoryDefaults: Record<string, number>; // SubjectCategory -> group number
}

// Défaut MINESEC : 3 groupes, mapping par catégorie de matière.
export const DEFAULT_BULLETIN_GROUPS: BulletinGroupsConfig = {
  groups: [
    { number: 1, label: "Groupe 1 — Littéraire & Langues" },
    { number: 2, label: "Groupe 2 — Scientifique & Technique" },
    { number: 3, label: "Groupe 3 — EPS & Autres" },
  ],
  assignments: {},
  categoryDefaults: {
    LITERARY: 1,
    LANGUAGE: 1,
    SCIENTIFIC: 2,
    TECHNICAL: 2,
    SPORTS: 3,
  },
};

/** Complète une config partielle avec les défauts MINESEC (robuste aux entrées incomplètes). */
export function normalizeBulletinGroups(config?: Partial<BulletinGroupsConfig> | null): BulletinGroupsConfig {
  if (!config || typeof config !== "object") return { ...DEFAULT_BULLETIN_GROUPS };
  const groups =
    Array.isArray(config.groups) && config.groups.length > 0
      ? config.groups
          .filter((g) => g && typeof g.number === "number")
          .map((g) => ({ number: g.number, label: String(g.label ?? `Groupe ${g.number}`) }))
      : DEFAULT_BULLETIN_GROUPS.groups;
  return {
    groups,
    assignments: (config.assignments && typeof config.assignments === "object" ? config.assignments : {}) as Record<string, number>,
    categoryDefaults: {
      ...DEFAULT_BULLETIN_GROUPS.categoryDefaults,
      ...(config.categoryDefaults && typeof config.categoryDefaults === "object" ? config.categoryDefaults : {}),
    },
  };
}

@Injectable()
export class TenantsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  /**
   * Création d'un établissement (Super Admin). Le Tenant n'est PAS un modèle
   * tenant-scopé, la création est donc transverse. Le code doit être unique ;
   * la config du moteur de calcul, si fournie, est validée puis stockée au
   * premier niveau de config_json (même forme que updateConfig).
   */
  async create(dto: CreateTenantDto): Promise<Tenant> {
    const existing = await this.prisma.tenant.findFirst({ where: { code: dto.code } });
    if (existing) {
      throw new ConflictException(`Un établissement avec le code "${dto.code}" existe déjà`);
    }

    let configJson: Prisma.InputJsonValue | undefined;
    if (dto.config !== undefined && dto.config !== null) {
      const parsed = calculationEngineSchema.safeParse(dto.config);
      if (!parsed.success) {
        throw new BadRequestException(
          "Configuration du moteur de calcul invalide — " +
            parsed.error.issues.map((issue) => `${issue.path.join(".") || "config"} : ${issue.message}`).join(" ; "),
        );
      }
      configJson = parsed.data as unknown as Prisma.InputJsonValue;
    }

    const tenant = await this.prisma.tenant.create({
      data: {
        name: dto.name,
        code: dto.code,
        type: dto.type,
        status: dto.status ?? TenantStatus.PUBLIC,
        address: dto.address ?? null,
        phone: dto.phone ?? null,
        email: dto.email ?? null,
        logoUrl: dto.logoUrl ?? null,
        ...(configJson !== undefined ? { configJson } : {}),
      },
    });

    await this.audit.log({
      action: "create",
      resource: "tenants",
      resourceId: tenant.id,
      newValue: { name: tenant.name, code: tenant.code, type: tenant.type },
    });

    return tenant;
  }

  /** Liste de tous les établissements (Super Admin — vue multi-établissements). */
  async findAll(): Promise<Tenant[]> {
    return this.prisma.tenant.findMany({
      where: { deletedAt: null },
      orderBy: { name: "asc" },
    });
  }

  async findOne(id: string): Promise<Tenant> {
    const tenant = await this.prisma.tenant.findFirst({ where: { id } });
    if (!tenant) {
      throw new NotFoundException("Établissement introuvable");
    }
    return tenant;
  }

  /**
   * Annuaire public des établissements ayant explicitement activé la
   * pré-inscription en ligne (publicEnrollmentEnabled=true). Utilisé par le
   * sélecteur d'établissement du formulaire public /inscription — aucune
   * authentification, donc aucun champ sensible retourné.
   */
  async findPublicList(search?: string): Promise<
    Array<{ id: string; code: string; name: string; type: Tenant["type"]; logoUrl: string | null }>
  > {
    const tenants = await this.prisma.tenant.findMany({
      where: {
        deletedAt: null,
        publicEnrollmentEnabled: true,
        ...(search ? { name: { contains: search, mode: "insensitive" as const } } : {}),
      },
      orderBy: { name: "asc" },
      take: 50,
    });
    return tenants.map((tenant) => ({
      id: tenant.id,
      code: tenant.code,
      name: tenant.name,
      type: tenant.type,
      logoUrl: tenant.logoUrl,
    }));
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
  /**
   * Configuration des groupes de matières du bulletin (MINESEC) pour cet
   * établissement. Stockée sous la clé `bulletinGroups` de Tenant.config_json,
   * séparée du moteur de calcul. Structure :
   *   { groups: [{ number, label }], assignments: { [subjectId]: number },
   *     categoryDefaults: { LITERARY, LANGUAGE, SCIENTIFIC, TECHNICAL, SPORTS } }
   * Si absente, un défaut MINESEC (3 groupes, mapping par catégorie) est renvoyé.
   */
  async getBulletinGroups(id: string): Promise<BulletinGroupsConfig> {
    const tenant = await this.findOne(id);
    const configJson = (tenant.configJson as Record<string, unknown> | null) ?? {};
    const stored = configJson.bulletinGroups as BulletinGroupsConfig | undefined;
    return normalizeBulletinGroups(stored);
  }

  async updateBulletinGroups(id: string, config: BulletinGroupsConfig): Promise<BulletinGroupsConfig> {
    const before = await this.findOne(id);
    const normalized = normalizeBulletinGroups(config);
    const existing = (before.configJson as Record<string, unknown> | null) ?? {};
    const merged = { ...existing, bulletinGroups: normalized };

    await this.prisma.tenant.update({
      where: { id },
      data: { configJson: merged as unknown as Prisma.InputJsonValue },
    });

    await this.audit.log({
      action: "update",
      resource: "tenants:bulletin-groups",
      resourceId: id,
      oldValue: existing.bulletinGroups ?? null,
      newValue: normalized,
    });
    return normalized;
  }

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
