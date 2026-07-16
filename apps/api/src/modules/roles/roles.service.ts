import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from "@nestjs/common";
import { Prisma } from "@scholaris/prisma";
import { buildPaginationMeta, DEFAULT_LIMIT, DEFAULT_PAGE, MAX_LIMIT, PaginatedResult } from "@scholaris/shared";
import { PrismaService } from "../../prisma/prisma.service";
import { AuditService } from "../audit/audit.service";
import { CreateRoleDto } from "./dto/create-role.dto";
import { UpdateRoleDto } from "./dto/update-role.dto";
import { FindRolesQueryDto } from "./dto/find-roles-query.dto";

const ROLE_SELECT = {
  id: true,
  tenantId: true,
  name: true,
  description: true,
  isSystem: true,
  createdAt: true,
  rolePermissions: {
    select: {
      permission: {
        select: { id: true, resource: true, action: true, description: true },
      },
    },
  },
} satisfies Prisma.RoleSelect;

function mapRole<T extends { rolePermissions: { permission: unknown }[] }>(role: T) {
  const { rolePermissions, ...rest } = role;
  return { ...rest, permissions: rolePermissions.map((rp) => rp.permission) };
}

@Injectable()
export class RolesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  /**
   * La middleware Prisma de scoping tenant (voir prisma.service.ts) applique déjà,
   * pour le modèle Role, un filtre `tenantId courant OU null` (rôles système) : on ne
   * repasse donc pas de `where.tenantId` explicite ici, ce qui casserait la visibilité
   * des rôles système (AND au lieu de OR).
   */
  async findAll(query: FindRolesQueryDto): Promise<PaginatedResult<unknown>> {
    const page = query.page && query.page > 0 ? query.page : DEFAULT_PAGE;
    const limit = query.limit && query.limit > 0 ? Math.min(query.limit, MAX_LIMIT) : DEFAULT_LIMIT;

    const where: Prisma.RoleWhereInput = query.search
      ? { name: { contains: query.search, mode: "insensitive" } }
      : {};

    const [roles, total] = await Promise.all([
      this.prisma.role.findMany({
        where,
        select: ROLE_SELECT,
        orderBy: { name: "asc" },
        skip: (page - 1) * limit,
        take: limit,
      }),
      this.prisma.role.count({ where }),
    ]);

    return { data: roles.map(mapRole), meta: buildPaginationMeta(total, page, limit) };
  }

  async findOne(id: string) {
    const role = await this.prisma.role.findFirst({ where: { id }, select: ROLE_SELECT });
    if (!role) {
      throw new NotFoundException("Rôle introuvable");
    }
    return mapRole(role);
  }

  async create(dto: CreateRoleDto, tenantId: string) {
    const permissionIds = dto.permissionIds ?? [];
    await this.assertPermissionsExist(permissionIds);

    try {
      const created = await this.prisma.$transaction(async (tx) => {
        const role = await tx.role.create({
          data: {
            tenantId,
            name: dto.name,
            description: dto.description,
            isSystem: false,
          },
        });

        if (permissionIds.length > 0) {
          await tx.rolePermission.createMany({
            data: permissionIds.map((permissionId) => ({ roleId: role.id, permissionId })),
          });
        }

        return tx.role.findFirst({ where: { id: role.id }, select: ROLE_SELECT });
      });

      await this.audit.log({
        action: "create",
        resource: "roles",
        resourceId: created!.id,
        newValue: { name: dto.name, description: dto.description, permissionIds },
      });

      return mapRole(created!);
    } catch (error) {
      if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === "P2002") {
        throw new ConflictException(`Un rôle nommé "${dto.name}" existe déjà dans cet établissement`);
      }
      throw error;
    }
  }

  async update(id: string, dto: UpdateRoleDto, tenantId: string) {
    const role = await this.prisma.role.findFirst({ where: { id } });
    if (!role) {
      throw new NotFoundException("Rôle introuvable");
    }
    this.assertMutable(role, tenantId);

    if (dto.permissionIds) {
      await this.assertPermissionsExist(dto.permissionIds);
    }

    try {
      const updated = await this.prisma.$transaction(async (tx) => {
        await tx.role.update({
          where: { id },
          data: {
            ...(dto.name !== undefined ? { name: dto.name } : {}),
            ...(dto.description !== undefined ? { description: dto.description } : {}),
          },
        });

        if (dto.permissionIds) {
          await tx.rolePermission.deleteMany({ where: { roleId: id } });
          if (dto.permissionIds.length > 0) {
            await tx.rolePermission.createMany({
              data: dto.permissionIds.map((permissionId) => ({ roleId: id, permissionId })),
            });
          }
        }

        return tx.role.findFirst({ where: { id }, select: ROLE_SELECT });
      });

      await this.audit.log({
        action: "update",
        resource: "roles",
        resourceId: id,
        oldValue: { name: role.name, description: role.description },
        newValue: dto,
      });

      return mapRole(updated!);
    } catch (error) {
      if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === "P2002") {
        throw new ConflictException(`Un rôle nommé "${dto.name}" existe déjà dans cet établissement`);
      }
      throw error;
    }
  }

  /**
   * Suppression : le modèle Role ne porte pas de `deleted_at` (pas de soft delete
   * disponible sans migration de schéma). On applique donc une suppression physique,
   * gardée par les mêmes garde-fous métier (rôle système, rôle encore utilisé).
   */
  async remove(id: string, tenantId: string): Promise<{ message: string }> {
    const role = await this.prisma.role.findFirst({ where: { id } });
    if (!role) {
      throw new NotFoundException("Rôle introuvable");
    }
    this.assertMutable(role, tenantId);

    const usageCount = await this.prisma.userRole.count({ where: { roleId: id } });
    if (usageCount > 0) {
      throw new ConflictException(
        `Impossible de supprimer ce rôle : il est encore assigné à ${usageCount} utilisateur(s)`,
      );
    }

    await this.prisma.$transaction([
      this.prisma.rolePermission.deleteMany({ where: { roleId: id } }),
      this.prisma.role.delete({ where: { id } }),
    ]);

    await this.audit.log({
      action: "delete",
      resource: "roles",
      resourceId: id,
      oldValue: { name: role.name },
    });

    return { message: "Rôle supprimé avec succès" };
  }

  /** Groupées par ressource, pour construire l'UI de sélection (checkboxes par module). */
  async findAllPermissionsGrouped(): Promise<Record<string, unknown[]>> {
    const permissions = await this.prisma.permission.findMany({
      orderBy: [{ resource: "asc" }, { action: "asc" }],
    });

    const grouped: Record<string, unknown[]> = {};
    for (const permission of permissions) {
      grouped[permission.resource] = grouped[permission.resource] ?? [];
      grouped[permission.resource].push(permission);
    }
    return grouped;
  }

  private assertMutable(role: { isSystem: boolean; tenantId: string | null }, tenantId: string): void {
    if (role.isSystem || role.tenantId === null) {
      throw new ForbiddenException("Les rôles système ne peuvent pas être modifiés ou supprimés");
    }
    if (role.tenantId !== tenantId) {
      throw new ForbiddenException("Accès refusé à un rôle d'un autre établissement");
    }
  }

  private async assertPermissionsExist(permissionIds: string[]): Promise<void> {
    if (permissionIds.length === 0) return;
    const found = await this.prisma.permission.findMany({ where: { id: { in: permissionIds } } });
    if (found.length !== permissionIds.length) {
      throw new BadRequestException("Un ou plusieurs identifiants de permission sont invalides");
    }
  }
}
